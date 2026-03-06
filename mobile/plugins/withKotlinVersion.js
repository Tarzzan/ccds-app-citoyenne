const { withGradleProperties, withAppBuildGradle } = require('expo/config-plugins');

function withKotlinVersion(config) {
  // 1. Inject kotlinVersion into gradle.properties
  config = withGradleProperties(config, (config) => {
    config.modResults = config.modResults.filter(
      (item) => !(item.type === 'property' && item.key === 'kotlinVersion')
    );
    config.modResults.push({
      type: 'property',
      key: 'kotlinVersion',
      value: '2.0.0',
    });
    return config;
  });

  // 2. Remove enableBundleCompression from app/build.gradle (deprecated in RN 0.74+)
  config = withAppBuildGradle(config, (config) => {
    if (config.modResults.contents) {
      config.modResults.contents = config.modResults.contents.replace(
        /\s*enableBundleCompression\s*=\s*.*\n?/g,
        '\n'
      );
    }
    return config;
  });

  return config;
}

module.exports = withKotlinVersion;
