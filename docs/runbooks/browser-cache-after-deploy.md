# Browsers load the new scripts after a deploy

Nextcloud's nginx config tells browsers to keep scripts and stylesheets for six
months. LibreSign's `.chunk.` files get new names on every build, so that's fine
for them. Its entry files (`js/libresign-*.mjs`, `css/libresign-*.css`,
`css/gopaperless.css`) keep the same name. With `debug` on, Nextcloud adds no
`?v=` to their URLs either, so a browser keeps the old entry file. That file
asks for chunks the deploy removed, and the page shows only its header and footer.

## The nginx rule

Add this to the Nextcloud server block, above the `# Serve static files` block
(`location ~ \.(?:css|js|mjs|svg|...)$`). nginx uses the first regex location
that matches, so the order matters.

```nginx
    # LibreSign's entry scripts and stylesheets keep their names on every build,
    # so browsers must check them on each load. Hashed .chunk. files keep the
    # six-month cache below. Keep this above the static files block.
    location ~ ^/[^/]+/libresign/(?:js|css)/(?!.*\.chunk\.)[^/]+\.(?:mjs|css)$ {
        try_files $uri /index.php$request_uri;
        add_header Cache-Control                     "no-cache"          always;
        add_header Referrer-Policy                   "no-referrer"       always;
        add_header X-Content-Type-Options            "nosniff"           always;
        add_header X-Frame-Options                   "SAMEORIGIN"        always;
        add_header X-Permitted-Cross-Domain-Policies "none"              always;
        add_header X-Robots-Tag                      "noindex, nofollow" always;
        access_log off;
    }
```

Copy any other `add_header` lines your static files block has (staging also
sends `X-XSS-Protection`). A location that sets its own headers drops the ones
it would otherwise inherit.

`no-cache` doesn't mean the browser downloads the file every time. It sends the
file's ETag and gets an empty 304 back when nothing changed.

Then run `nginx -t` and reload nginx.

On staging and gopaperless.ke this goes in the Nextcloud nginx, not in Nginx
Proxy Manager in front of it. If only the proxy can be edited, the same location
there needs `proxy_hide_header Cache-Control;` and the proxy's usual `proxy_pass`.

## Check it

```bash
curl -sI https://staging.gopaperless.dev/custom_apps/libresign/js/libresign-main.mjs | grep -i cache-control
# Cache-Control: no-cache
```

A `.chunk.mjs` file from the same folder still answers
`Cache-Control: public, max-age=15778463`.

## People who already have the old files

A browser that cached an entry file before the rule went in keeps it until it
expires, so each of those users needs one hard refresh (Ctrl+Shift+R, or
Cmd+Shift+R on a Mac). After that, every deploy reaches them on their next page
load.
