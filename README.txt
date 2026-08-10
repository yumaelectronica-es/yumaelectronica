LIANA TRADE — Clone HTML statique
==================================

Contenu
-------
- index.html              : page d'accueil
- categoria/*.html         : 16 pages catégories (toutes contiennent 100% des produits de leur rayon)
- producto/*.html          : 5289 fiches produit individuelles
- assets/css/app.css        : CSS d'origine du site (Tailwind compilé, téléchargé depuis lianatrade.com)
- assets/img/                : logo, favicon, icônes de catégories, logos de marques

Chiffres
--------
- 5364 produits récupérés (site source), 5289 fiches produit générées après déduplication
- 16 rayons/catégories, correspondant exactement au découpage du menu du site (aucun doublon,
  aucun produit manquant par rapport au sitemap public du site : 5364 URLs /producto/ recensées)
- Header, méga-menu (toutes catégories/sous-catégories), footer, bandeau de contact, modale de
  cookies : repris à l'identique du HTML réel du site (mêmes classes CSS Tailwind)

Comment prévisualiser
----------------------
Les liens internes utilisent des chemins racine (/categoria/..., /assets/...). Un double-clic sur
index.html dans l'explorateur de fichiers NE fonctionnera PAS correctement (les liens et le CSS ne
se chargeront pas) car le navigateur interprète "/" comme la racine du disque, pas comme le dossier
du site. Il faut le servir via un petit serveur local ou l'héberger normalement :

Option A (fournie) : lancez ce dossier avec serve.ps1 (PowerShell), puis ouvrez
http://localhost:8080/ dans votre navigateur :
    powershell -File serve.ps1

Option B : si vous avez Node.js -> npx serve .
Option C : déployez directement le dossier sur votre hébergement (Netlify, Vercel, OVH, o2switch,
un simple FTP...) — les chemins racine fonctionneront nativement une fois hébergé.

Ce qui est fidèle à l'original
-------------------------------
- Mise en page, couleurs, typographies : CSS réel du site, copié tel quel.
- Header/nav/méga-menu/footer : HTML réel, uniquement les liens internes réécrits vers les pages
  générées localement (categoria/*.html, producto/*.html).
- Fiches produit : image(s), marque, nom, prix (barré + prix actuel si remise), classe énergétique,
  livraison gratuite — toutes extraites du site réel pour chacun des 5364 produits.
- Menu mobile / méga-menu déroulant / modale cookies : interactivité conservée via Alpine.js
  (chargé depuis CDN), qui pilote déjà ces composants dans le HTML d'origine.

Ce qui a été volontairement simplifié
---------------------------------------
- Pas de backend : le bouton "Ajouter au panier", la recherche, la connexion, les avis clients et le
  formulaire newsletter sont visuels mais non fonctionnels (le site d'origine tourne sur Laravel/
  Livewire côté serveur, non reproductible en HTML statique).
- Page d'accueil : les blocs "Offres du jour", "Meilleures ventes" présentent un échantillon réel de
  produits (avec vraies remises quand il y en a) plutôt que la sélection exacte et le compte à
  rebours du jour de la capture, qui seraient de toute façon obsolètes dès le lendemain.
- Pages hors catalogue (connexion, blog, mentions légales, contact, etc.) ne sont pas régénérées :
  les liens correspondants renvoient vers les pages réelles sur lianatrade.com.
- Images produits : elles restent hébergées sur lianatrade.com/storage/... (le CDN d'origine du
  site) plutôt que d'être re-téléchargées une à une (~5300 images, plusieurs Go) ; comme c'est votre
  propre CDN, ce lien direct fonctionne sans problème pour un usage courant.
