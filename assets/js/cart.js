// Yuma Electrónica — client-side cart (localStorage only, no backend).
(function () {
    'use strict';
    var CART_KEY = 'yuma_cart_v1';

    function getCart() {
        try {
            var raw = localStorage.getItem(CART_KEY);
            var cart = raw ? JSON.parse(raw) : [];
            return Array.isArray(cart) ? cart : [];
        } catch (e) {
            return [];
        }
    }

    function saveCart(cart) {
        localStorage.setItem(CART_KEY, JSON.stringify(cart));
        updateCartBadge();
        updateCartSnapshot();
        window.dispatchEvent(new CustomEvent('cart-updated'));
    }

    function lineKey(item) {
        return [item.id, item.warranty, item.removal, item.installation].join('|');
    }

    function addToCart(item) {
        var cart = getCart();
        var key = lineKey(item);
        var existing = cart.find(function (c) { return lineKey(c) === key; });
        if (existing) {
            existing.qty += item.qty;
        } else {
            cart.push(item);
        }
        saveCart(cart);
        showToast((item.qty > 1 ? item.qty + ' unidades añadidas' : 'Producto añadido') + ' al carrito');
    }

    function removeFromCart(index) {
        var cart = getCart();
        cart.splice(index, 1);
        saveCart(cart);
    }

    function setQty(index, qty) {
        var cart = getCart();
        if (!cart[index]) return;
        qty = parseInt(qty, 10);
        cart[index].qty = (isNaN(qty) || qty < 1) ? 1 : qty;
        saveCart(cart);
    }

    // Mutate a single cart line (e.g. toggle its warranty/removal/installation
    // options) via a callback, then persist. Used by the cart page's inline pickers.
    function updateCartItem(index, mutator) {
        var cart = getCart();
        if (!cart[index]) return;
        mutator(cart[index]);
        saveCart(cart);
    }

    function unitPrice(item) {
        return item.basePrice + item.warrantyCost + item.removalCost + item.installationCost;
    }

    // Demo coupon codes — this is a static site with no order backend, so these are
    // validated client-side for demonstration only. Wire this up to a real discount
    // service before taking payments.
    var DEMO_COUPONS = {
        'BIENVENIDA10': { type: 'percent', value: 10, label: '10% de descuento', minSubtotal: 0 },
        'AHORRO20': { type: 'percent', value: 20, label: '20% en pedidos +500€', minSubtotal: 500 },
    };

    function taxRegionForPostal(cp) {
        if (!/^\d{5}$/.test(cp || '')) return 'peninsula';
        var prefix = cp.slice(0, 2);
        if (prefix === '35' || prefix === '38') return 'canarias';
        if (prefix === '51' || prefix === '52') return 'ceuta_melilla';
        return 'peninsula';
    }

    // Baleares (07) and Canarias (35/38) ship slower and, on the express tier,
    // cost more than the peninsula/Ceuta-Melilla mainland network.
    function isIslandPostal(cp) {
        if (!/^\d{5}$/.test(cp || '')) return false;
        var prefix = cp.slice(0, 2);
        return prefix === '07' || prefix === '35' || prefix === '38';
    }

    var SHIPPING_RATES = {
        free: { costMainland: 0, costIsland: 0, etaMainland: '3-5 días laborables', etaIsland: '4-7 días laborables' },
        express: { costMainland: 22, costIsland: 38, etaMainland: '24-48h', etaIsland: '48-72h' }
    };

    function shippingInfo(postalCode, method) {
        var rate = SHIPPING_RATES[method] || SHIPPING_RATES.free;
        var island = isIslandPostal(postalCode);
        return {
            cost: island ? rate.costIsland : rate.costMainland,
            eta: island ? rate.etaIsland : rate.etaMainland,
            isIsland: island
        };
    }

    // Shared pricing/tax math used by both the cart page and checkout page, so the
    // total shown at checkout can never drift from the total shown in the cart.
    // shippingMethod defaults to 'free' for call sites (like the cart page) that
    // don't yet ask the shopper to pick a shipping tier.
    function orderTotals(cart, postalCode, appliedCoupon, shippingMethod) {
        shippingMethod = shippingMethod || 'free';
        var productsSubtotal = cart.reduce(function (s, i) { return s + i.basePrice * i.qty; }, 0);
        var warrantySubtotal = cart.reduce(function (s, i) { return s + i.warrantyCost * i.qty; }, 0);
        var removalSubtotal = cart.reduce(function (s, i) { return s + i.removalCost * i.qty; }, 0);
        var installationSubtotal = cart.reduce(function (s, i) { return s + i.installationCost * i.qty; }, 0);
        var grossTotal = productsSubtotal + warrantySubtotal + removalSubtotal + installationSubtotal;
        var couponDiscountAmount = (appliedCoupon && appliedCoupon.type === 'percent')
            ? grossTotal * (appliedCoupon.value / 100) : 0;
        var discountedGrossTotal = Math.max(0, grossTotal - couponDiscountAmount);
        var ship = shippingInfo(postalCode, shippingMethod);
        var totalWithShipping = discountedGrossTotal + ship.cost;
        var region = taxRegionForPostal(postalCode);
        var baseExTax = totalWithShipping / 1.21;
        var finalTotal = totalWithShipping;
        if (region === 'canarias') finalTotal = baseExTax * 1.07;
        else if (region === 'ceuta_melilla') finalTotal = baseExTax;
        return {
            productsSubtotal: productsSubtotal, warrantySubtotal: warrantySubtotal,
            removalSubtotal: removalSubtotal, installationSubtotal: installationSubtotal,
            grossTotal: grossTotal, couponDiscountAmount: couponDiscountAmount,
            discountedGrossTotal: discountedGrossTotal,
            shippingMethod: shippingMethod, shippingCost: ship.cost, shippingEta: ship.eta, isIsland: ship.isIsland,
            region: region, baseExTax: baseExTax, finalTotal: finalTotal
        };
    }

    function fmtEUR(n) {
        return (n || 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // Small handoff so the checkout page can pick up the postal code / coupon the
    // shopper already entered on the cart page, without re-asking for them.
    var CHECKOUT_META_KEY = 'yuma_checkout_meta_v1';
    function getCheckoutMeta() {
        try { return JSON.parse(localStorage.getItem(CHECKOUT_META_KEY)) || {}; } catch (e) { return {}; }
    }
    function saveCheckoutMeta(meta) {
        localStorage.setItem(CHECKOUT_META_KEY, JSON.stringify(meta));
    }

    function clearCart() {
        saveCart([]);
    }

    // Demo customer accounts — client-side only (localStorage), no server/auth backend.
    // Good enough to demonstrate a login/register flow on a static site; do not reuse
    // this pattern for a real store (passwords are stored in plain text).
    var ACCOUNTS_KEY = 'yuma_accounts_v1';
    var SESSION_KEY = 'yuma_session_v1';
    function getAccounts() {
        try { return JSON.parse(localStorage.getItem(ACCOUNTS_KEY)) || {}; } catch (e) { return {}; }
    }
    function findAccount(email) {
        var accounts = getAccounts();
        return accounts[(email || '').trim().toLowerCase()] || null;
    }
    function saveAccount(account) {
        var accounts = getAccounts();
        accounts[account.email.trim().toLowerCase()] = account;
        localStorage.setItem(ACCOUNTS_KEY, JSON.stringify(accounts));
    }
    function getSession() {
        var email = localStorage.getItem(SESSION_KEY);
        return email ? findAccount(email) : null;
    }
    function setSession(email) {
        if (email) localStorage.setItem(SESSION_KEY, email.trim().toLowerCase());
        else localStorage.removeItem(SESSION_KEY);
    }
    // Admin-only: every registered account as a flat array (getAccounts()
    // returns them keyed by email, which x-for can't iterate directly).
    function getAllAccountsList() {
        var accounts = getAccounts();
        return Object.keys(accounts).map(function (k) { return accounts[k]; });
    }

    // Demo order history — client-side only (localStorage), tied to the account
    // email that was logged in at checkout. Guest checkouts aren't tied to any
    // account, so they never appear in "Mis pedidos".
    var ORDERS_KEY = 'yuma_orders_v1';
    function getAllOrders() {
        try {
            var orders = JSON.parse(localStorage.getItem(ORDERS_KEY));
            return Array.isArray(orders) ? orders : [];
        } catch (e) { return []; }
    }
    function saveOrder(order) {
        var orders = getAllOrders();
        orders.unshift(order);
        localStorage.setItem(ORDERS_KEY, JSON.stringify(orders));
    }
    function getOrdersForEmail(email) {
        email = (email || '').trim().toLowerCase();
        if (!email) return [];
        return getAllOrders().filter(function (o) { return (o.email || '').toLowerCase() === email; });
    }
    function updateOrder(orderNumber, patch) {
        var orders = getAllOrders();
        var idx = orders.findIndex(function (o) { return o.orderNumber === orderNumber; });
        if (idx === -1) return;
        orders[idx] = Object.assign({}, orders[idx], patch);
        localStorage.setItem(ORDERS_KEY, JSON.stringify(orders));
    }
    // Public lookup (order number + email) — used by the order-tracking page,
    // which doesn't require being logged in (the shopper may be a guest, or
    // just following the link from their confirmation screen).
    function findOrder(orderNumber, email) {
        orderNumber = (orderNumber || '').trim().toUpperCase();
        email = (email || '').trim().toLowerCase();
        if (!orderNumber || !email) return null;
        return getAllOrders().find(function (o) {
            return (o.orderNumber || '').toUpperCase() === orderNumber && (o.email || '').toLowerCase() === email;
        }) || null;
    }

    // Simulated order progress — there's no real fulfilment backend, so status
    // is derived from how much time has passed since the order was placed,
    // using the same shipping ETAs shown at checkout (see SHIPPING_RATES).
    var ORDER_STEPS = [
        { key: 'received', label: 'Pedido recibido' },
        { key: 'paid', label: 'Pago confirmado' },
        { key: 'preparing', label: 'Preparando tu pedido' },
        { key: 'shipped', label: 'Enviado' },
        { key: 'delivered', label: 'Entregado' }
    ];
    function orderStatus(order) {
        var placedAt = new Date(order.date).getTime();
        var hoursElapsed = (Date.now() - placedAt) / 36e5;
        var express = order.shippingMethod === 'express';
        var tPaid = 3;
        var tPreparing = 14;
        var tShipped = express ? 20 : 30;
        var tDelivered = express ? 48 : 120;

        var stepIndex = 0;
        if (hoursElapsed >= tDelivered) stepIndex = 4;
        else if (hoursElapsed >= tShipped) stepIndex = 3;
        else if (hoursElapsed >= tPreparing) stepIndex = 2;
        else if (hoursElapsed >= tPaid) stepIndex = 1;

        // The shop team can override the simulated step from the admin panel
        // (e.g. after manually checking the uploaded payment proof).
        var isManual = typeof order.statusOverride === 'number';
        if (isManual) stepIndex = order.statusOverride;

        var thresholds = [0, tPaid, tPreparing, tShipped, tDelivered];
        var dates = thresholds.map(function (h) { return new Date(placedAt + h * 36e5); });

        return {
            steps: ORDER_STEPS,
            stepIndex: stepIndex,
            dates: dates,
            isDelivered: stepIndex === 4,
            isManual: isManual
        };
    }
    function setOrderStatus(orderNumber, stepIndex) {
        updateOrder(orderNumber, { statusOverride: stepIndex });
    }

    // Admin: disabled products — client-side only. There's no CMS behind this
    // static catalog, so "disabling" a product doesn't remove its page; it
    // just hides the buy button and flags it as unavailable wherever it's
    // shown (see applyProductAvailability, called on every page load).
    var DISABLED_PRODUCTS_KEY = 'yuma_disabled_products_v1';
    function getDisabledProducts() {
        try {
            var d = JSON.parse(localStorage.getItem(DISABLED_PRODUCTS_KEY));
            return Array.isArray(d) ? d : [];
        } catch (e) { return []; }
    }
    function isProductDisabled(id) {
        return getDisabledProducts().indexOf(String(id)) > -1;
    }
    function setProductDisabled(id, disabled) {
        var list = getDisabledProducts();
        id = String(id);
        var idx = list.indexOf(id);
        if (disabled && idx === -1) list.push(id);
        else if (!disabled && idx > -1) list.splice(idx, 1);
        localStorage.setItem(DISABLED_PRODUCTS_KEY, JSON.stringify(list));
    }
    // Finds every "Añadir al carrito" button on the current page (cards and
    // the product-detail buy button both carry addToCart({id:'...',...}) in
    // either onclick or Alpine's @click) and disables the ones matching a
    // disabled product id, with a visible "No disponible" state.
    function applyProductAvailability() {
        var disabled = getDisabledProducts();
        if (!disabled.length) return;
        var set = {};
        disabled.forEach(function (id) { set[id] = true; });
        document.querySelectorAll('button').forEach(function (btn) {
            var attr = btn.getAttribute('onclick') || btn.getAttribute('@click') || '';
            if (attr.indexOf('addToCart(') === -1) return;
            var m = attr.match(/id:'([^']*)'/);
            if (!m || !set[m[1]]) return;
            btn.disabled = true;
            btn.classList.add('opacity-50', 'grayscale', 'pointer-events-none');
            btn.textContent = 'No disponible';
            var article = btn.closest('article');
            var imgLink = article ? article.querySelector('a[href*="/producto/"]') : null;
            if (imgLink && !imgLink.querySelector('.unavailable-badge')) {
                var badge = document.createElement('span');
                badge.className = 'unavailable-badge absolute inset-0 z-30 flex items-center justify-center bg-white/85 text-center text-caption font-bold text-ink-700';
                badge.textContent = 'No disponible';
                imgLink.appendChild(badge);
            }
        });
    }

    // Admin: store payment settings (IBAN, beneficiary, Bizum number, which
    // methods are enabled) — read by the checkout page instead of hardcoded
    // values, so the shop team can update them without touching the code.
    var PAYMENT_CONFIG_KEY = 'yuma_payment_config_v1';
    var DEFAULT_PAYMENT_CONFIG = {
        beneficiary: 'Yuma Electrónica S.L.',
        iban: 'ES00 0000 0000 0000 0000 0000',
        bic: 'YUMAESMMXXX',
        bankName: 'Banco Yuma',
        bizumNumber: '622 000 000',
        bizumBeneficiary: 'Yuma Electrónica S.L.',
        transferEnabled: true,
        bizumEnabled: true
    };
    function getPaymentConfig() {
        try {
            var saved = JSON.parse(localStorage.getItem(PAYMENT_CONFIG_KEY)) || {};
            return Object.assign({}, DEFAULT_PAYMENT_CONFIG, saved);
        } catch (e) { return Object.assign({}, DEFAULT_PAYMENT_CONFIG); }
    }
    function savePaymentConfig(patch) {
        var cfg = Object.assign({}, getPaymentConfig(), patch);
        localStorage.setItem(PAYMENT_CONFIG_KEY, JSON.stringify(cfg));
        return cfg;
    }

    // Admin: notifications — a computed feed (new orders, proofs awaiting
    // review), not a push mechanism (there's no server to push from). Read
    // fresh on every admin page load. Dismissed ids are remembered so they
    // don't reappear.
    var NOTIF_DISMISSED_KEY = 'yuma_notif_dismissed_v1';
    function getDismissedNotifications() {
        try {
            var d = JSON.parse(localStorage.getItem(NOTIF_DISMISSED_KEY));
            return Array.isArray(d) ? d : [];
        } catch (e) { return []; }
    }
    function dismissNotification(id) {
        var list = getDismissedNotifications();
        if (list.indexOf(id) === -1) {
            list.push(id);
            localStorage.setItem(NOTIF_DISMISSED_KEY, JSON.stringify(list));
        }
    }
    function getNotifications() {
        var dismissed = {};
        getDismissedNotifications().forEach(function (id) { dismissed[id] = true; });
        var orders = getAllOrders();
        var items = [];
        orders.forEach(function (o) {
            var newId = 'new-' + o.orderNumber;
            if (!dismissed[newId] && (Date.now() - new Date(o.date).getTime()) < 48 * 36e5) {
                items.push({ id: newId, type: 'order', date: o.date, text: 'Nuevo pedido ' + o.orderNumber + ' de ' + o.email, orderNumber: o.orderNumber });
            }
            var proofId = 'proof-' + o.orderNumber;
            if (!dismissed[proofId] && o.paymentProofName && typeof o.statusOverride !== 'number') {
                items.push({ id: proofId, type: 'proof', date: o.paymentProofAt || o.date, text: 'Justificante pendiente de revisar: ' + o.orderNumber, orderNumber: o.orderNumber });
            }
        });
        items.sort(function (a, b) { return new Date(b.date) - new Date(a.date); });
        return items;
    }

    // Session-scoped activity log — this browser's own page views and cart
    // state only. There's no server, so this can never show real visitors;
    // it's an honest per-browser log, useful mainly for testing.
    var VISITS_KEY = 'yuma_visits_v1';
    var VISITS_MAX = 150;
    function logVisit() {
        if (location.pathname.indexOf('/admin.html') > -1) return;
        try {
            var list = JSON.parse(localStorage.getItem(VISITS_KEY)) || [];
            if (!Array.isArray(list)) list = [];
            list.push({ path: location.pathname, title: document.title, at: new Date().toISOString() });
            if (list.length > VISITS_MAX) list = list.slice(list.length - VISITS_MAX);
            localStorage.setItem(VISITS_KEY, JSON.stringify(list));
        } catch (e) {}
    }
    function getVisits() {
        try {
            var list = JSON.parse(localStorage.getItem(VISITS_KEY));
            return Array.isArray(list) ? list.slice().reverse() : [];
        } catch (e) { return []; }
    }
    function clearVisits() {
        localStorage.removeItem(VISITS_KEY);
    }

    // In-progress cart snapshot for this browser — updated whenever the cart
    // changes, cleared once an order is placed. Lets the admin see "carts
    // that didn't check out yet" for THIS session; can't reflect other
    // shoppers without a real backend.
    var CART_SNAPSHOT_KEY = 'yuma_cart_snapshot_v1';
    function updateCartSnapshot() {
        var cart = getCart();
        if (!cart.length) {
            localStorage.removeItem(CART_SNAPSHOT_KEY);
            return;
        }
        localStorage.setItem(CART_SNAPSHOT_KEY, JSON.stringify({
            items: cart,
            total: cartTotal(),
            updatedAt: new Date().toISOString()
        }));
    }
    function getAbandonedCarts() {
        try {
            var snap = JSON.parse(localStorage.getItem(CART_SNAPSHOT_KEY));
            return snap ? [snap] : [];
        } catch (e) { return []; }
    }

    function cartCount() {
        return getCart().reduce(function (sum, i) { return sum + i.qty; }, 0);
    }

    function cartTotal() {
        return getCart().reduce(function (sum, i) { return sum + unitPrice(i) * i.qty; }, 0);
    }

    function updateCartBadge() {
        var n = cartCount();
        document.querySelectorAll('.cart-badge').forEach(function (el) {
            el.textContent = n;
        });
    }

    // Demo wishlist — client-side only (localStorage). Stores a lightweight
    // snapshot of each product (whatever addToCart already had on hand), keyed
    // by product id, so the wishlist page can render without a backend.
    var WISHLIST_KEY = 'yuma_wishlist_v1';
    function getWishlist() {
        try {
            var list = JSON.parse(localStorage.getItem(WISHLIST_KEY));
            return Array.isArray(list) ? list : [];
        } catch (e) { return []; }
    }
    function isInWishlist(id) {
        id = String(id);
        return getWishlist().some(function (p) { return String(p.id) === id; });
    }
    function toggleWishlist(product) {
        var list = getWishlist();
        var id = String(product.id);
        var idx = list.findIndex(function (p) { return String(p.id) === id; });
        var added;
        if (idx > -1) {
            list.splice(idx, 1);
            added = false;
        } else {
            list.unshift(product);
            added = true;
        }
        localStorage.setItem(WISHLIST_KEY, JSON.stringify(list));
        updateWishlistUI();
        showToast(added ? 'Añadido a tu lista de deseos' : 'Eliminado de tu lista de deseos');
        return added;
    }
    function removeFromWishlist(id) {
        var list = getWishlist().filter(function (p) { return String(p.id) !== String(id); });
        localStorage.setItem(WISHLIST_KEY, JSON.stringify(list));
        updateWishlistUI();
    }
    function wishlistCount() {
        return getWishlist().length;
    }
    function updateWishlistUI() {
        var n = wishlistCount();
        document.querySelectorAll('.wishlist-badge').forEach(function (el) {
            el.textContent = n;
            el.classList.toggle('hidden', n === 0);
        });
        document.querySelectorAll('[data-wishlist-id]').forEach(function (btn) {
            var active = isInWishlist(btn.getAttribute('data-wishlist-id'));
            btn.classList.toggle('text-danger-600', active);
            btn.classList.toggle('text-ink-400', !active);
            var svg = btn.querySelector('svg');
            if (svg) svg.setAttribute('fill', active ? 'currentColor' : 'none');
        });
    }

    // Demo compare list — client-side only (localStorage), capped at 4 products
    // so the comparison table stays readable.
    var COMPARE_KEY = 'yuma_compare_v1';
    var COMPARE_MAX = 4;
    function getCompareList() {
        try {
            var list = JSON.parse(localStorage.getItem(COMPARE_KEY));
            return Array.isArray(list) ? list : [];
        } catch (e) { return []; }
    }
    function isInCompare(id) {
        id = String(id);
        return getCompareList().some(function (p) { return String(p.id) === id; });
    }
    function toggleCompare(product) {
        var list = getCompareList();
        var id = String(product.id);
        var idx = list.findIndex(function (p) { return String(p.id) === id; });
        var added;
        if (idx > -1) {
            list.splice(idx, 1);
            added = false;
        } else {
            if (list.length >= COMPARE_MAX) {
                showToast('Puedes comparar hasta ' + COMPARE_MAX + ' productos. Quita alguno para añadir otro.');
                return false;
            }
            list.push(product);
            added = true;
        }
        localStorage.setItem(COMPARE_KEY, JSON.stringify(list));
        updateCompareUI();
        showToast(added ? 'Añadido a comparar' : 'Eliminado de comparar');
        return added;
    }
    function removeFromCompare(id) {
        var list = getCompareList().filter(function (p) { return String(p.id) !== String(id); });
        localStorage.setItem(COMPARE_KEY, JSON.stringify(list));
        updateCompareUI();
    }
    function compareCount() {
        return getCompareList().length;
    }
    function updateCompareUI() {
        var n = compareCount();
        document.querySelectorAll('.compare-badge').forEach(function (el) {
            el.textContent = n;
            el.classList.toggle('hidden', n === 0);
        });
        document.querySelectorAll('[data-compare-id]').forEach(function (btn) {
            var active = isInCompare(btn.getAttribute('data-compare-id'));
            btn.classList.toggle('text-brand-600', active);
            btn.classList.toggle('bg-brand-50', active);
            btn.classList.toggle('text-ink-400', !active);
        });
    }

    var toastTimer = null;
    function showToast(msg) {
        var t = document.getElementById('cart-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'cart-toast';
            t.className = 'fixed bottom-5 left-1/2 z-toast -translate-x-1/2 rounded-full bg-ink-900 px-5 py-3 text-body-sm font-semibold text-white shadow-modal transition-opacity duration-300';
            t.style.opacity = '0';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.opacity = '1';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.style.opacity = '0'; }, 2200);
    }

    window.getCart = getCart;
    window.addToCart = addToCart;
    window.removeFromCart = removeFromCart;
    window.setQty = setQty;
    window.updateCartItem = updateCartItem;
    window.unitPrice = unitPrice;
    window.clearCart = clearCart;
    window.cartCount = cartCount;
    window.cartTotal = cartTotal;
    window.showToast = showToast;
    window.DEMO_COUPONS = DEMO_COUPONS;
    window.taxRegionForPostal = taxRegionForPostal;
    window.isIslandPostal = isIslandPostal;
    window.orderTotals = orderTotals;
    window.shippingInfo = shippingInfo;
    window.fmtEUR = fmtEUR;
    window.getCheckoutMeta = getCheckoutMeta;
    window.saveCheckoutMeta = saveCheckoutMeta;
    window.findAccount = findAccount;
    window.saveAccount = saveAccount;
    window.getSession = getSession;
    window.setSession = setSession;
    window.getAllAccountsList = getAllAccountsList;
    window.saveOrder = saveOrder;
    window.getAllOrders = getAllOrders;
    window.getOrdersForEmail = getOrdersForEmail;
    window.updateOrder = updateOrder;
    window.findOrder = findOrder;
    window.orderStatus = orderStatus;
    window.setOrderStatus = setOrderStatus;
    window.ORDER_STEPS = ORDER_STEPS;
    window.getWishlist = getWishlist;
    window.isInWishlist = isInWishlist;
    window.toggleWishlist = toggleWishlist;
    window.removeFromWishlist = removeFromWishlist;
    window.wishlistCount = wishlistCount;
    window.getCompareList = getCompareList;
    window.isInCompare = isInCompare;
    window.toggleCompare = toggleCompare;
    window.removeFromCompare = removeFromCompare;
    window.compareCount = compareCount;
    window.COMPARE_MAX = COMPARE_MAX;
    window.getDisabledProducts = getDisabledProducts;
    window.isProductDisabled = isProductDisabled;
    window.setProductDisabled = setProductDisabled;
    window.getPaymentConfig = getPaymentConfig;
    window.savePaymentConfig = savePaymentConfig;
    window.getNotifications = getNotifications;
    window.dismissNotification = dismissNotification;
    window.getVisits = getVisits;
    window.clearVisits = clearVisits;
    window.getAbandonedCarts = getAbandonedCarts;

    document.addEventListener('DOMContentLoaded', function () {
        updateCartBadge();
        updateWishlistUI();
        updateCompareUI();
        applyProductAvailability();
        logVisit();
    });
})();
