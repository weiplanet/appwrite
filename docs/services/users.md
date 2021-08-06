The Users service allows you to manage your project users. Use this service to search, block, and view your users' info, current sessions, and latest activity logs. You can also use the Users service to edit your users' preferences and personal info.

> ## Users API vs Account API
> While the Users API is integrated from the server-side and operates in an admin scope with access to all your project users, the Account API operates in the scope of the current logged in user and usually using a client-side integration.
> 
> Some of the Account API methods are available from the server SDK when you authenticate with JWT. This allows you to perform server-side actions on behalf of your project user.