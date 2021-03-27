## Getting Started

### Init your SDK
Initialize your SDK code with your project ID which can be found in your project settings page and your new API secret Key project API keys section.

```js
const sdk = require('node-appwrite');

let client = new sdk.Client();

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;
```

### Make Your First Request
Once your SDK object is set, create any of the Appwrite service objects and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the API References section.

```js
let users = new sdk.Users(client);

let promise = users.create('email@example.com', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```

### Full Example
```js
const sdk = require('node-appwrite');

let client = new sdk.Client();

client
    .setEndpoint('https://[HOSTNAME_OR_IP]/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2') // Your project ID
    .setKey('919c2d18fb5d4...a2ae413da83346ad2') // Your secret API key
;

let users = new sdk.Users(client);
let promise = users.create('email@example.com', 'password');

promise.then(function (response) {
    console.log(response);
}, function (error) {
    console.log(error);
});
```

### Learn more
You can use followng resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Node Playground](https://github.com/appwrite/playground-for-node)
