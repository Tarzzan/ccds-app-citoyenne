const { withGradleProperties } = require('expo/config-plugins');

module.exports = function withKotlinVersion(config) {
  return withGradleProperties(config, (config) => {
    // Remove existing kotlinVersion if present
    config.modResults = config.modResults.filter(
      (item) => !(item.type === 'property' && item.key === 'kotlinVersion')
    );
    // Add kotlinVersion 2.0.0
    config.modResults.push({
      type: 'property',
      key: 'kotlinVersion',
      value: '2.0.0',
    });
    return config;
  });
};
