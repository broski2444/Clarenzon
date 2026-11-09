package com.clarenzon.warehouse;

import android.Manifest;
import android.annotation.SuppressLint;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Bundle;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;

import com.journeyapps.barcodescanner.ScanContract;
import com.journeyapps.barcodescanner.ScanOptions;

import org.json.JSONObject;

public class MainActivity extends AppCompatActivity {
    private WebView webView;
    private ProgressBar progress;
    private View errorBox;
    private boolean pageFailed;

    private final ActivityResultLauncher<String> cameraPermission =
            registerForActivityResult(new ActivityResultContracts.RequestPermission(), granted -> {
                if (Boolean.TRUE.equals(granted)) {
                    openNativeScanner();
                } else {
                    Toast.makeText(this, R.string.camera_needed, Toast.LENGTH_LONG).show();
                }
            });

    private final ActivityResultLauncher<ScanOptions> barcodeLauncher =
            registerForActivityResult(new ScanContract(), result -> {
                if (result != null && result.getContents() != null) {
                    deliverBarcode(result.getContents());
                }
            });

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        if (!ServerPrefs.isReady(this)) {
            startActivity(new Intent(this, SetupActivity.class));
            finish();
            return;
        }

        setContentView(R.layout.activity_main);
        webView = findViewById(R.id.webView);
        progress = findViewById(R.id.progress);
        errorBox = findViewById(R.id.errorBox);
        Button retryBtn = findViewById(R.id.retryBtn);
        Button settingsBtn = findViewById(R.id.settingsBtn);
        retryBtn.setOnClickListener(v -> loadWarehouse());
        settingsBtn.setOnClickListener(v -> openSettings());

        configureWebView();
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack();
                } else {
                    finish();
                }
            }
        });
        loadWarehouse();
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void configureWebView() {
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setSupportZoom(false);
        settings.setBuiltInZoomControls(false);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        settings.setUserAgentString(settings.getUserAgentString() + " ClarenzonWarehouseApp");

        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptThirdPartyCookies(webView, true);

        webView.addJavascriptInterface(new NativeBridge(), "ClarenzonNativeApp");
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progress.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
                progress.setProgress(newProgress);
            }

            @Override
            public void onPermissionRequest(PermissionRequest request) {
                runOnUiThread(() -> request.grant(request.getResources()));
            }
        });
        webView.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                pageFailed = false;
                errorBox.setVisibility(View.GONE);
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    pageFailed = true;
                    showError();
                }
            }

            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                String host = error.getUrl() == null ? "" : Uri.parse(error.getUrl()).getHost();
                if (isLocalHost(host)) {
                    handler.proceed();
                } else {
                    handler.cancel();
                }
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                String scheme = uri.getScheme() == null ? "" : uri.getScheme();
                if ("http".equals(scheme) || "https".equals(scheme)) {
                    return false;
                }
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                } catch (Exception ignored) {
                }
                return true;
            }
        });
    }

    private void loadWarehouse() {
        pageFailed = false;
        errorBox.setVisibility(View.GONE);
        webView.loadUrl(ServerPrefs.url(this));
    }

    private void showError() {
        errorBox.setVisibility(View.VISIBLE);
        TextView text = findViewById(R.id.errorText);
        text.setText(getString(R.string.load_error) + "\n\n" + ServerPrefs.url(this));
    }

    void launchScanner() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA)
                == PackageManager.PERMISSION_GRANTED) {
            openNativeScanner();
        } else {
            cameraPermission.launch(Manifest.permission.CAMERA);
        }
    }

    private void openNativeScanner() {
        ScanOptions options = new ScanOptions();
        options.setPrompt(getString(R.string.scan_prompt));
        options.setBeepEnabled(true);
        options.setOrientationLocked(true);
        options.setCaptureActivity(PortraitCaptureActivity.class);
        options.setDesiredBarcodeFormats(ScanOptions.ALL_CODE_TYPES);
        barcodeLauncher.launch(options);
    }

    private void deliverBarcode(String code) {
        String js = "window.__clarenzonOnNativeScan && window.__clarenzonOnNativeScan("
                + JSONObject.quote(code) + ")";
        webView.evaluateJavascript(js, null);
    }

    void openSettings() {
        startActivity(new Intent(this, SetupActivity.class));
    }

    private static boolean isLocalHost(String host) {
        if (host == null) {
            return false;
        }
        String h = host.toLowerCase();
        return h.equals("localhost")
                || h.equals("127.0.0.1")
                || h.equals("10.0.2.2")
                || h.startsWith("192.168.")
                || h.startsWith("10.")
                || h.startsWith("172.16.");
    }

    public class NativeBridge {
        @JavascriptInterface
        public boolean isNative() {
            return true;
        }

        @JavascriptInterface
        public void scanBarcode() {
            runOnUiThread(MainActivity.this::launchScanner);
        }

        @JavascriptInterface
        public void openServerSettings() {
            runOnUiThread(MainActivity.this::openSettings);
        }

        @JavascriptInterface
        public String getServerUrl() {
            return ServerPrefs.url(MainActivity.this);
        }
    }
}
