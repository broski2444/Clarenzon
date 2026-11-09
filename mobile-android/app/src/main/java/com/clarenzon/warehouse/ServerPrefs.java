package com.clarenzon.warehouse;

import android.content.Context;
import android.content.SharedPreferences;

final class ServerPrefs {
    private static final String PREF = "clarenzon_warehouse";
    private static final String KEY_URL = "server_url";
    private static final String KEY_READY = "server_ready";

    private ServerPrefs() {}

    static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREF, Context.MODE_PRIVATE);
    }

    static String url(Context context) {
        String saved = prefs(context).getString(KEY_URL, "");
        if (saved == null || saved.trim().isEmpty()) {
            return BuildConfig.DEFAULT_SERVER_URL;
        }
        return normalize(saved);
    }

    static boolean isReady(Context context) {
        return prefs(context).getBoolean(KEY_READY, false);
    }

    static void save(Context context, String raw) {
        prefs(context).edit()
                .putString(KEY_URL, normalize(raw))
                .putBoolean(KEY_READY, true)
                .apply();
    }

    static String normalize(String raw) {
        String url = raw == null ? "" : raw.trim();
        if (url.isEmpty()) {
            return BuildConfig.DEFAULT_SERVER_URL;
        }
        if (!url.startsWith("http://") && !url.startsWith("https://")) {
            url = "http://" + url;
        }
        if (url.endsWith("/mobile")) {
            url = url + "/index.php";
        } else if (url.endsWith("/mobile/")) {
            url = url + "index.php";
        }
        return url;
    }

    static String lanDefault() {
        return BuildConfig.DEFAULT_SERVER_URL;
    }

    static String emulatorDefault() {
        return "http://10.0.2.2/CAPSTONE/mobile/index.php";
    }
}
