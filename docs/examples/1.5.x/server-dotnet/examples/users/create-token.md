using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Users users = new Users(client);

Token result = await users.CreateToken(
    userId: "<USER_ID>",
    length: 4, // optional
    expire: 60 // optional
);