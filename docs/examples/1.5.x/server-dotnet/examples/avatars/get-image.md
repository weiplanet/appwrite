using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetSession(""); // The user session to authenticate with

Avatars avatars = new Avatars(client);

byte[] result = await avatars.GetImage(
    url: "https://example.com",
    width: 0, // optional
    height: 0 // optional
);