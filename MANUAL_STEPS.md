# Manual steps for 1.0.0

## After 1.0.0 is released on WordPress.org

Deprecate the companion command line tool on npm. This needs your npm login (`npm whoami` must print the package owner):

    npm deprecate @abdalsalaam/chatgpt-wp-connect "No longer needed: AI Provider for ChatGPT 1.0.0 connects with Sign in with ChatGPT under Settings > ChatGPT."

Check it worked:

    npm view @abdalsalaam/chatgpt-wp-connect deprecated

It prints the message above. Deprecating does not unpublish anything; installs still work and show the message.

The deprecation note in the README of `Abdalsalaam/chatgpt-wp-connect` is a separate pull request in that repository.
