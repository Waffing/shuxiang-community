plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val siteUrl = providers.gradleProperty("SITE_URL")
    .orElse("https://forum.example.com")
    .get()

android {
    namespace = "com.resourceforum.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.resourceforum.app"
        minSdk = 24
        targetSdk = 35
        versionCode = 2
        versionName = "1.1.0"

        buildConfigField("String", "SITE_URL", "\"${siteUrl.replace("\\", "\\\\").replace("\"", "\\\"")}\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        buildConfig = true
    }
}
