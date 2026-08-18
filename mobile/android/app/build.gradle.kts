import com.flutter.gradle.tasks.FlutterTask

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Apply Google Services only when google-services.json is present (Firebase FCM).
val googleServicesFile = file("google-services.json")
if (googleServicesFile.exists()) {
    apply(plugin = "com.google.gms.google-services")
}

android {
    namespace = "com.example.mobile"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        isCoreLibraryDesugaringEnabled = true
    }

    defaultConfig {
        // Specify the application ID.
        applicationId = "com.example.mobile"

        // Firebase Messaging requires minSdk 21+.
        minSdk = maxOf(23, flutter.minSdkVersion)

        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    buildTypes {
        release {
            // Signing with debug keys for now.
            signingConfig = signingConfigs.getByName("debug")

            isMinifyEnabled = false
            isShrinkResources = false
        }
    }

    // Avoid AGP lintVital crash on plugin sources that can block release APK assembly.
    lint {
        checkReleaseBuilds = false
        abortOnError = false
    }
}

// Required by flutter_local_notifications + native ParamGoldFirebaseMessagingService.
dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
    implementation("com.google.firebase:firebase-messaging")
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

// Keep the full MaterialIcons font so release builds do not lose icons.
gradle.taskGraph.whenReady {
    allTasks.filterIsInstance<FlutterTask>().forEach { task ->
        task.treeShakeIcons = false
    }
}