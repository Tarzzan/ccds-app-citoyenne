const { withAppBuildGradle, withDangerousMod } = require('expo/config-plugins');
const fs = require('fs');
const path = require('path');

/**
 * Config plugin that:
 * 1. Removes the deprecated 'enableBundleCompression' from app/build.gradle
 * 2. Forces Gradle wrapper to 8.10.2 (last version compatible with Kotlin 1.9.25 + KSP 1.9.25-1.0.20)
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

  // 2. Force Gradle wrapper version to 8.10.2
  config = withDangerousMod(config, [
    'android',
    async (config) => {
      const wrapperPropsPath = path.join(
        config.modRequest.platformProjectRoot,
        'gradle',
        'wrapper',
        'gradle-wrapper.properties'
      );

      if (fs.existsSync(wrapperPropsPath)) {
        let contents = fs.readFileSync(wrapperPropsPath, 'utf-8');
        contents = contents.replace(
          /gradle-[\d.]+-bin\.zip/,
          'gradle-8.10.2-bin.zip'
        );
        fs.writeFileSync(wrapperPropsPath, contents);
      }

      return config;
    },
  ]);

  return config;
}

module.exports = withFixBuildGradle;
