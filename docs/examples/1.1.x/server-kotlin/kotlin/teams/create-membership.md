import io.appwrite.Client
import io.appwrite.services.Teams

suspend fun main() {
    val client = Client(context)
      .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
      .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

    val teams = Teams(client)
    val response = teams.createMembership(
        teamId = "[TEAM_ID]",
        email = "email@example.com",
        roles = listOf(),
        url = "https://example.com",
    )
    val json = response.body?.string()
}