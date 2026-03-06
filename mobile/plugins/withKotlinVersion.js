const { withAppBuildGradle } = require('expo/config-plugins');

/**
 * Config plugin that removes the deprecated 'enableBundleCompression' property
 * from the generated android/app/build.gradle file.
 * This property was removed in React Native 0.74+ and causes build failures.
 * 
 * NOTE: Do NOT override kotlinVersion - Expo SDK 54 requires Kotlin 1.9.x
 */
function withFixBuildGradle(config) {
  config = withAppBuildGradle(config, (config) => {
    if (config.modResults.contents) {
      // Remove enableBundleCompression line (any variation)
      config.modResults.contents = config.modResults.contents.replace(
        /.*enableBundleCompression.*\n?/g,
        ''
      );
    }
    return config;
  });

  return config;
}

module.exports = withFixBuildGradle;
