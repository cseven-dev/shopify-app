<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>My App Settings</title>
</head>
<body>
  <div id="app">
    <h1>App settings</h1>
    <!-- Put your existing settings form here -->
  </div>

  <!-- OPTIONAL: small App Bridge integration for nicer look -->
  <script src="https://unpkg.com/@shopify/app-bridge@3"></script>
  <script>
    (function () {
      try {
        const AppBridge = window['app-bridge'];
        const createApp = AppBridge && AppBridge.createApp;
        if (!createApp) return;
        const app = createApp({
          apiKey: "{{ config('services.shopify.api_key') ?? '' }}", // optional but useful
          shopOrigin: new URLSearchParams(window.location.search).get('shop'),
        });
        // Example: create a title bar (no auth required for this)
        const { TitleBar } = window['app-bridge'].actions;
        TitleBar.create(app, { title: "Settings" });
      } catch(e) { /* ignore if not available */ }
    })();
  </script>
</body>
</html>