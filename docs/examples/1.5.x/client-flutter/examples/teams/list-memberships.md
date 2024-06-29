import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://cloud.appwrite.io/v1') // Your API Endpoint
    .setProject('5df5acd0d48c2'); // Your project ID

Teams teams = Teams(client);

MembershipList result = await teams.listMemberships(
    teamId: '<TEAM_ID>',
    queries: [], // optional
    search: '<SEARCH>', // optional
);
