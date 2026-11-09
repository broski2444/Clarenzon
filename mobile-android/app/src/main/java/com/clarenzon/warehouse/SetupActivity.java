package com.clarenzon.warehouse;

import android.content.Intent;
import android.os.Bundle;
import android.widget.Button;
import android.widget.EditText;

import androidx.appcompat.app.AppCompatActivity;

public class SetupActivity extends AppCompatActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_setup);

        EditText urlInput = findViewById(R.id.urlInput);
        urlInput.setText(ServerPrefs.url(this));

        findViewById(R.id.saveBtn).setOnClickListener(v -> {
            ServerPrefs.save(this, urlInput.getText().toString());
            openMain();
        });
        findViewById(R.id.lanBtn).setOnClickListener(v -> {
            urlInput.setText(ServerPrefs.lanDefault());
        });
        findViewById(R.id.emulatorBtn).setOnClickListener(v -> {
            urlInput.setText(ServerPrefs.emulatorDefault());
        });
    }

    private void openMain() {
        Intent intent = new Intent(this, MainActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(intent);
        finish();
    }
}
