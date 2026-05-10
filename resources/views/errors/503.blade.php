<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Maintenance</title>
        <meta name="color-scheme" content="light dark">
        <link rel="icon" type="image/png" href="/favicons/favicon-96x96.png" sizes="96x96" />
        <link rel="icon" type="image/svg+xml" href="/favicons/favicon.svg" />
        <link rel="shortcut icon" href="/favicons/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/favicons/apple-touch-icon.png" />
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

            body {
                min-height: 100dvh;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 2lh;
                background-image: url('/static/503-bg.avif');
                background-size: cover;
                background-position: center;
                font-family: system-ui, sans-serif;
            }

            .box {
                max-width: 480px;
                padding: 2.5rem 3rem;
                border-radius: 12px;
                background-color: rgb(0 0 0 / 0.55);
                backdrop-filter: blur(8px);
                color: #fff;
                text-align: center;
            }

            h1 {
                font-size: 2rem;
                font-weight: 700;
                margin-bottom: 1rem;
                letter-spacing: 0.02em;
            }

            h2 {
                font-size: 1.4rem;
                font-weight: 600;
                margin-bottom: 1rem;
                letter-spacing: 0.02em;
            }

            p {
                font-size: 1rem;
                line-height: 1.6;
                color: rgb(255 255 255 / 0.85);
            }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>Maintenance</h1>
            <p>The site is currently undergoing maintenance and will be back shortly. Thank you for your patience.</p>
        </div>
    </body>
</html>
