param([string]$Root = "$PSScriptRoot", [int]$Port = 8080)

$mime = @{
    ".html"="text/html; charset=utf-8"; ".css"="text/css"; ".js"="application/javascript"
    ".png"="image/png"; ".svg"="image/svg+xml"; ".webp"="image/webp"; ".jpg"="image/jpeg"; ".jpeg"="image/jpeg"
    ".xml"="application/xml"; ".csv"="text/csv"; ".txt"="text/plain"; ".ico"="image/x-icon"
}

$rootFull = [System.IO.Path]::GetFullPath($Root)
$listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Any, $Port)
$listener.Start()
Write-Host "Serving $rootFull on http://0.0.0.0:$Port/ (Ctrl+C to stop)"

while ($true) {
    $client = $listener.AcceptTcpClient()
    $client.ReceiveTimeout = 5000
    $client.SendTimeout = 5000
    try {
        $stream = $client.GetStream()
        $reader = New-Object System.IO.StreamReader($stream)
        $requestLine = $reader.ReadLine()
        while (($headerLine = $reader.ReadLine()) -and $headerLine -ne "") {}

        if ($requestLine -and $requestLine -match '^GET\s+(\S+)\s+HTTP') {
            $path = ($matches[1] -split '\?')[0]
            $path = [System.Uri]::UnescapeDataString($path)
            if ($path -eq "/") { $path = "/index.html" }
            $file = [System.IO.Path]::GetFullPath((Join-Path $rootFull ($path.TrimStart('/'))))

            if ($file.StartsWith($rootFull) -and (Test-Path $file -PathType Leaf)) {
                $ext = [System.IO.Path]::GetExtension($file)
                $ctype = $mime[$ext]; if (-not $ctype) { $ctype = "application/octet-stream" }
                $bytes = [System.IO.File]::ReadAllBytes($file)
                $header = "HTTP/1.1 200 OK`r`nContent-Type: $ctype`r`nContent-Length: $($bytes.Length)`r`nConnection: close`r`n`r`n"
                $stream.Write([System.Text.Encoding]::ASCII.GetBytes($header), 0, ([System.Text.Encoding]::ASCII.GetByteCount($header)))
                $stream.Write($bytes, 0, $bytes.Length)
            } else {
                $body = [System.Text.Encoding]::UTF8.GetBytes("404 Not Found")
                $header = "HTTP/1.1 404 Not Found`r`nContent-Type: text/plain`r`nContent-Length: $($body.Length)`r`nConnection: close`r`n`r`n"
                $stream.Write([System.Text.Encoding]::ASCII.GetBytes($header), 0, ([System.Text.Encoding]::ASCII.GetByteCount($header)))
                $stream.Write($body, 0, $body.Length)
            }
        }
    } catch {
    } finally {
        $client.Close()
    }
}
