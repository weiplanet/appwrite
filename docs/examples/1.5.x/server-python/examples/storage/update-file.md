from appwrite.client import Client

client = Client()
client.set_endpoint('https://cloud.appwrite.io/v1') # Your API Endpoint
client.set_project('5df5acd0d48c2') # Your project ID
client.set_session('') # The user session to authenticate with

storage = Storage(client)

result = storage.update_file(
    bucket_id = '<BUCKET_ID>',
    file_id = '<FILE_ID>',
    name = '<NAME>', # optional
    permissions = ["read("any")"] # optional
)
