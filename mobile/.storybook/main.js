/** @type { import('@storybook/react-native').StorybookConfig } */
const config = {
  stories: ['../src/**/*.stories.@(js|jsx|ts|tsx)'],
  addons: [
    '@storybook/addon-ondevice-controls',
    '@storybook/addon-ondevice-actions',
    '@storybook/addon-ondevice-backgrounds',
  ],
  framework: {
    name: '@storybook/react-native',
    options: {},
  },
};

module.exports = config;
