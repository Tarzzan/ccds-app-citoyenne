const { withAppBuildGradle, withDangerousMod } = require('expo/config-plugins');
const fs = require('fs');
const path = require('path');

/**
 * Config plugin that:
 * 1. Removes the deprecated 'enableBundleCompression' from app/build.gradle
 * 2. Forces Gradle wrapper to 8.13 (required by RN 0.81.5)
 * 3. Sets kotlinVersion=2.0.21 in gradle.properties (required by RN 0.81.5)
 */
function withFixBuildGradle(config) {
  // 1. Remove enableBundleCompression from app/build.gradle
  config = withAppBuildGradle(config, (config) => {
    if (config.modResults.contents) {
      config.modResults.contents = config.modResults.contents.replace(
        /.*enableBundleCompression.*\n?/g,
        ''
      );
    }
    return config;
  });

  // 2. Force Gradle wrapper to 8.13 + set kotlinVersion=2.0.21
  config = withDangerousMod(config, [
    'android',
    async (config) => {
      const androidRoot = config.modRequest.platformProjectRoot;

      // Force Gradle wrapper version to 8.10.2
      const wrapperPropsPath = path.join(
        androidRoot,
        'gradle',
        'wrapper',
        'gradle-wrapper.properties'
      );
      if (fs.existsSync(wrapperPropsPath)) {
        let contents = fs.readFileSync(wrapperPropsPath, 'utf-8');
        contents = contents.replace(
          /gradle-[\d.]+-bin\.zip/,
          'gradle-8.13-bin.zip'
        );
        fs.writeFileSync(wrapperPropsPath, contents);
        console.log('[withFixBuildGradle] Forced Gradle 8.13');
      }

      // Set kotlinVersion=2.0.21 in gradle.properties
      // RN 0.81.5 requires Kotlin 2.0.x
      const gradlePropsPath = path.join(androidRoot, 'gradle.properties');
      if (fs.existsSync(gradlePropsPath)) {
        let contents = fs.readFileSync(gradlePropsPath, 'utf-8');
        // Remove any existing kotlinVersion line
        contents = contents.replace(/^kotlinVersion=.*$/m, '');
        // Add kotlinVersion=2.0.21
        contents = contents.trim() + '\nkotlinVersion=2.0.21\n';
        fs.writeFileSync(gradlePropsPath, contents);
        console.log('[withFixBuildGradle] Set kotlinVersion=2.0.21');
      }

      return config;
    },
  ]);

  return config;
}

module.exports = withFixBuildGradle;
