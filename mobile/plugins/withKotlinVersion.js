const { withAppBuildGradle } = require('expo/config-plugins');

/**
 * Config plugin that removes the deprecated 'enableBundleCompression' property
 * from the generated android/app/build.gradle file.
 */
function withFixBuildGradle(config) {
  config = withAppBuildGradle(config, (config) => {
    if (config.modResults.contents) {
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
