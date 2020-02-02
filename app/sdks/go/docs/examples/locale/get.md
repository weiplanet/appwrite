package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("")
    client.SetKey("")

    var service := appwrite.Locale{
        client: &client
    }

    var response, error := service.Get()

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}