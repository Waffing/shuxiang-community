package com.resourceforum.app

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.app.AlertDialog
import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.view.View
import android.webkit.CookieManager
import android.webkit.DownloadListener
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebStorage
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.ImageButton
import android.widget.PopupMenu
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL

class MainActivity : Activity() {
    private lateinit var webView: WebView
    private lateinit var progressBar: ProgressBar
    private lateinit var errorPanel: View
    private lateinit var errorMessage: TextView
    private var pendingDownload: PendingDownload? = null
    private var filePathCallback: ValueCallback<Array<Uri>>? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        webView = findViewById(R.id.web_view)
        progressBar = findViewById(R.id.page_progress)
        errorPanel = findViewById(R.id.error_panel)
        errorMessage = findViewById(R.id.error_message)

        configureWebView()
        findViewById<ImageButton>(R.id.action_refresh).setOnClickListener { reloadPage() }
        findViewById<ImageButton>(R.id.action_more).setOnClickListener { showAppMenu(it) }
        findViewById<Button>(R.id.retry_button).setOnClickListener { reloadPage() }

        if (savedInstanceState == null) {
            loadSite()
        } else {
            webView.restoreState(savedInstanceState)
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView() {
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
        CookieManager.getInstance().setAcceptCookie(true)

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = false
            allowContentAccess = false
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            mediaPlaybackRequiresUserGesture = true
            setSupportZoom(true)
            builtInZoomControls = true
            displayZoomControls = false
            userAgentString = "$userAgentString ResourceForumApp/${BuildConfig.VERSION_NAME}"
        }
        updateCacheMode()
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val uri = request.url
                if (request.isForMainFrame && isSameOrigin(uri, Uri.parse(BuildConfig.SITE_URL))) {
                    return false
                }
                if (!request.isForMainFrame && uri.scheme in WEB_SCHEMES) {
                    return false
                }
                openExternal(uri)
                return true
            }

            override fun onPageStarted(view: WebView, url: String, favicon: Bitmap?) {
                errorPanel.visibility = View.GONE
            }

            override fun onReceivedError(
                view: WebView,
                request: WebResourceRequest,
                error: WebResourceError
            ) {
                if (request.isForMainFrame) {
                    showLoadError(error.description.toString())
                }
            }
        }
        webView.webChromeClient = object : WebChromeClient() {
            override fun onProgressChanged(view: WebView, newProgress: Int) {
                progressBar.progress = newProgress
                progressBar.visibility = if (newProgress in 0..99) View.VISIBLE else View.GONE
            }

            override fun onShowFileChooser(
                webView: WebView,
                filePathCallback: ValueCallback<Array<Uri>>,
                fileChooserParams: FileChooserParams
            ): Boolean {
                this@MainActivity.filePathCallback?.onReceiveValue(null)
                this@MainActivity.filePathCallback = filePathCallback
                return runCatching {
                    startActivityForResult(fileChooserParams.createIntent(), FILE_CHOOSER_REQUEST)
                    true
                }.getOrElse {
                    this@MainActivity.filePathCallback = null
                    filePathCallback.onReceiveValue(null)
                    Toast.makeText(this@MainActivity, R.string.no_app_available, Toast.LENGTH_SHORT).show()
                    false
                }
            }
        }
        webView.setDownloadListener(DownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            val name = URLUtil.guessFileName(url, contentDisposition, mimeType)
                .replace(Regex("[\\\\/:*?\"<>|]"), "_")
            val download = PendingDownload(url, userAgent, mimeType, name)
            AlertDialog.Builder(this)
                .setTitle(R.string.download_confirm_title)
                .setMessage(getString(R.string.download_confirm_message, name))
                .setNegativeButton(R.string.cancel, null)
                .setPositiveButton(R.string.confirm) { _, _ -> prepareDownload(download) }
                .show()
        })
    }

    private fun loadSite() {
        val uri = Uri.parse(BuildConfig.SITE_URL)
        if (uri.scheme !in WEB_SCHEMES || uri.host.isNullOrBlank()) {
            showLoadError(getString(R.string.invalid_site_url))
            return
        }
        updateCacheMode()
        webView.loadUrl(uri.toString())
    }

    private fun reloadPage() {
        errorPanel.visibility = View.GONE
        updateCacheMode()
        if (webView.url.isNullOrBlank()) loadSite() else webView.reload()
    }

    private fun updateCacheMode() {
        webView.settings.cacheMode = if (isNetworkAvailable()) {
            WebSettings.LOAD_DEFAULT
        } else {
            WebSettings.LOAD_CACHE_ELSE_NETWORK
        }
    }

    private fun isNetworkAvailable(): Boolean {
        val manager = getSystemService(ConnectivityManager::class.java)
        val network = manager.activeNetwork ?: return false
        val capabilities = manager.getNetworkCapabilities(network) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    private fun isSameOrigin(first: Uri, second: Uri): Boolean {
        if (first.scheme !in WEB_SCHEMES || second.scheme !in WEB_SCHEMES) return false
        return first.scheme.equals(second.scheme, ignoreCase = true) &&
            first.host.equals(second.host, ignoreCase = true) &&
            effectivePort(first) == effectivePort(second)
    }

    private fun effectivePort(uri: Uri): Int = when {
        uri.port != -1 -> uri.port
        uri.scheme.equals("https", ignoreCase = true) -> 443
        else -> 80
    }

    private fun showAppMenu(anchor: View) {
        PopupMenu(this, anchor).apply {
            menu.add(getString(R.string.open_browser)).setOnMenuItemClickListener {
                openExternal(Uri.parse(webView.url ?: BuildConfig.SITE_URL))
                true
            }
            menu.add(getString(R.string.clear_cache)).setOnMenuItemClickListener {
                confirmClearCache()
                true
            }
            menu.add(getString(R.string.check_update)).setOnMenuItemClickListener {
                checkForUpdates()
                true
            }
            show()
        }
    }

    private fun confirmClearCache() {
        AlertDialog.Builder(this)
            .setTitle(R.string.clear_cache)
            .setMessage(R.string.confirm_clear_cache)
            .setNegativeButton(R.string.cancel, null)
            .setPositiveButton(R.string.confirm) { _, _ ->
                webView.clearCache(true)
                WebStorage.getInstance().deleteAllData()
                Toast.makeText(this, R.string.cache_cleared, Toast.LENGTH_SHORT).show()
            }
            .show()
    }

    private fun checkForUpdates() {
        Toast.makeText(this, R.string.checking_update, Toast.LENGTH_SHORT).show()
        Thread {
            val result = runCatching {
                val manifestUrl = URL("${BuildConfig.SITE_URL.trimEnd('/')}/app-update.json")
                val connection = manifestUrl.openConnection() as HttpURLConnection
                try {
                    connection.connectTimeout = 8_000
                    connection.readTimeout = 8_000
                    connection.setRequestProperty("Accept", "application/json")
                    connection.setRequestProperty("User-Agent", webView.settings.userAgentString)
                    if (connection.responseCode !in 200..299) {
                        error("Update server returned HTTP ${connection.responseCode}")
                    }
                    val payload = connection.inputStream.bufferedReader().use { it.readText() }.trimStart('\uFEFF')
                    val json = JSONObject(payload)
                    val releaseAvailable = json.optBoolean("releaseAvailable", true)
                    val versionCode = json.getInt("versionCode")
                    val versionName = json.getString("versionName")
                    val rawDownloadUrl = json.optString("downloadUrl").takeIf { it.isNotBlank() && it != "null" }
                    val downloadUrl = rawDownloadUrl?.let { URI(manifestUrl.toString()).resolve(it).toString() }
                    if (releaseAvailable && downloadUrl == null) error("Release download URL is missing")
                    if (downloadUrl != null && !isTrustedUpdateUri(Uri.parse(downloadUrl))) {
                        error("Release download URL is not trusted")
                    }
                    val sha256 = json.optString("sha256").lowercase().takeIf { it.matches(Regex("^[a-f0-9]{64}$")) }
                    val fileSize = json.optLong("fileSize", -1).takeIf { it > 0 }
                    val changelog = json.optJSONArray("changelog")?.let { entries ->
                        (0 until entries.length()).mapNotNull { index -> entries.optString(index).takeIf(String::isNotBlank) }
                    }.orEmpty()
                    UpdateInfo(
                        releaseAvailable,
                        versionCode,
                        versionName,
                        downloadUrl,
                        sha256,
                        fileSize,
                        changelog,
                        json.optInt("minSupportedVersionCode", 1)
                    )
                } finally {
                    connection.disconnect()
                }
            }
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
                result.fold(
                    onSuccess = { showUpdateResult(it) },
                    onFailure = {
                        Toast.makeText(this, R.string.update_failed, Toast.LENGTH_LONG).show()
                    }
                )
            }
        }.start()
    }

    private fun showUpdateResult(update: UpdateInfo) {
        if (!update.releaseAvailable) {
            Toast.makeText(this, R.string.no_official_release, Toast.LENGTH_SHORT).show()
            return
        }
        if (update.versionCode <= BuildConfig.VERSION_CODE) {
            Toast.makeText(this, R.string.already_latest, Toast.LENGTH_SHORT).show()
            return
        }
        val details = buildString {
            append(getString(R.string.update_message, BuildConfig.VERSION_NAME, update.versionName))
            update.fileSize?.let { append("\n文件大小：${it / 1024 / 1024} MB") }
            update.sha256?.let { append("\nSHA-256：$it") }
            if (update.changelog.isNotEmpty()) append("\n\n更新内容：\n${update.changelog.joinToString("\n") { "• $it" }}")
        }
        val mandatory = BuildConfig.VERSION_CODE < update.minSupportedVersionCode
        val builder = AlertDialog.Builder(this)
            .setTitle(getString(R.string.update_available, update.versionName))
            .setMessage(details)
            .setPositiveButton(R.string.download_update) { _, _ ->
                openExternal(Uri.parse(requireNotNull(update.downloadUrl)))
            }
            .setCancelable(!mandatory)
        if (!mandatory) builder.setNegativeButton(R.string.cancel, null)
        builder.show()
    }

    private fun isTrustedUpdateUri(uri: Uri): Boolean {
        val site = Uri.parse(BuildConfig.SITE_URL)
        return uri.scheme.equals("https", ignoreCase = true) && isSameOrigin(uri, site)
    }

    private fun openExternal(uri: Uri) {
        val scheme = uri.scheme?.lowercase()
        if (scheme == null || scheme in BLOCKED_SCHEMES) {
            Toast.makeText(this, R.string.no_app_available, Toast.LENGTH_SHORT).show()
            return
        }
        val intent = Intent(Intent.ACTION_VIEW, uri).addCategory(Intent.CATEGORY_BROWSABLE)
        runCatching { startActivity(intent) }
            .onFailure {
                Toast.makeText(this, R.string.no_app_available, Toast.LENGTH_SHORT).show()
            }
    }

    private fun prepareDownload(download: PendingDownload) {
        if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.P &&
            checkSelfPermission(Manifest.permission.WRITE_EXTERNAL_STORAGE) != PackageManager.PERMISSION_GRANTED
        ) {
            pendingDownload = download
            requestPermissions(arrayOf(Manifest.permission.WRITE_EXTERNAL_STORAGE), DOWNLOAD_PERMISSION_REQUEST)
            return
        }
        enqueueDownload(download)
    }

    private fun enqueueDownload(download: PendingDownload) {
        val uri = Uri.parse(download.url)
        if (uri.scheme !in WEB_SCHEMES) {
            openExternal(uri)
            return
        }
        runCatching {
            val request = DownloadManager.Request(uri)
                .setTitle(download.fileName)
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, download.fileName)
                .setAllowedOverMetered(true)
                .setAllowedOverRoaming(false)
            if (download.mimeType.isNotBlank()) request.setMimeType(download.mimeType)
            if (download.userAgent.isNotBlank()) request.addRequestHeader("User-Agent", download.userAgent)
            CookieManager.getInstance().getCookie(download.url)?.let {
                request.addRequestHeader("Cookie", it)
            }
            webView.url?.let { request.addRequestHeader("Referer", it) }
            getSystemService(DownloadManager::class.java).enqueue(request)
        }.onSuccess {
            Toast.makeText(this, R.string.download_started, Toast.LENGTH_SHORT).show()
        }.onFailure {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_LONG).show()
        }
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == DOWNLOAD_PERMISSION_REQUEST) {
            val download = pendingDownload
            pendingDownload = null
            if (grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED && download != null) {
                enqueueDownload(download)
            }
        }
    }

    @Deprecated("Required for WebChromeClient file chooser compatibility")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        if (requestCode == FILE_CHOOSER_REQUEST) {
            val result = WebChromeClient.FileChooserParams.parseResult(resultCode, data)
            filePathCallback?.onReceiveValue(result)
            filePathCallback = null
            return
        }
        super.onActivityResult(requestCode, resultCode, data)
    }

    private fun showLoadError(details: String) {
        progressBar.visibility = View.GONE
        errorMessage.text = if (details.isBlank()) getString(R.string.load_failed) else details
        errorPanel.visibility = View.VISIBLE
    }

    override fun onSaveInstanceState(outState: Bundle) {
        webView.saveState(outState)
        super.onSaveInstanceState(outState)
    }

    @Suppress("DEPRECATION", "OVERRIDE_DEPRECATION")
    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack() else super.onBackPressed()
    }

    override fun onResume() {
        super.onResume()
        updateCacheMode()
        webView.onResume()
    }

    override fun onPause() {
        webView.onPause()
        super.onPause()
    }

    override fun onDestroy() {
        filePathCallback?.onReceiveValue(null)
        filePathCallback = null
        webView.stopLoading()
        webView.destroy()
        super.onDestroy()
    }

    private data class PendingDownload(
        val url: String,
        val userAgent: String,
        val mimeType: String,
        val fileName: String
    )

    private data class UpdateInfo(
        val releaseAvailable: Boolean,
        val versionCode: Int,
        val versionName: String,
        val downloadUrl: String?,
        val sha256: String?,
        val fileSize: Long?,
        val changelog: List<String>,
        val minSupportedVersionCode: Int
    )

    private companion object {
        const val DOWNLOAD_PERMISSION_REQUEST = 1001
        const val FILE_CHOOSER_REQUEST = 1002
        val WEB_SCHEMES = setOf("http", "https")
        val BLOCKED_SCHEMES = setOf("javascript", "file", "content", "data", "about")
    }
}
