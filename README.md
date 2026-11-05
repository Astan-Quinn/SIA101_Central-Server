# SIA101 Central Server

PHP 8.1+ REST API hosted on the Central Server computer. UtilityTrack and LifeCycleTrack send sanitized records over the local Wi-Fi. The API authenticates each subsystem and stores accepted records in the Central Server Supabase project.

## Requirements

- PHP 8.1+ with cURL enabled
- Apache with mod_rewrite enabled (included with XAMPP)
- A Supabase project for the Central Server

## Configure the central Supabase project

1. In the Central Server Supabase project, open SQL Editor.
2. Run database/schema.sql to create public.central_records and its unique source identity.
3. In Project Settings → API Keys, copy the project URL and a server-side secret key (sb_secret_...). A legacy service_role key also works. Keep this key only on the Central Server computer; secret keys bypass Row Level Security and must never be sent to a browser or committed.
4. Copy .env.example to .env. Set SUPABASE_URL, SUPABASE_SECRET_KEY, and two different long random subsystem tokens. Do not commit .env.

## Run under XAMPP on the same Wi-Fi

1. Place this repository in the XAMPP web root, for example C:\xampp\htdocs\SIA101_Central-Server.
2. Start Apache. The API base path will be http://localhost/SIA101_Central-Server/public.
3. On the Central Server computer, run ipconfig and find the Wi-Fi adapter's IPv4 address, for example 192.168.1.50.
4. Allow inbound Apache traffic on its port (usually 80) in Windows Firewall for the Private network profile. Ensure Wi-Fi client isolation is disabled on the router.
5. From another computer on the same Wi-Fi, open http://192.168.1.50/SIA101_Central-Server/public/api/v1/health, replacing the example IP with the actual address. A configured API returns {"ok":true,...}.
6. Set each subsystem's CENTRAL_API_BASE_URL to http://192.168.1.50/SIA101_Central-Server/public and its own token. Do not use localhost from subsystem computers.

Use HTTPS before transmitting bearer tokens on Wi-Fi where other devices may observe traffic. HTTP does not encrypt tokens or payloads.

## API routes

- GET /api/v1/health — confirms required Supabase configuration is present.
- POST /api/v1/ingest/utility_bill and DELETE /api/v1/ingest/utility_bill/{id}
- POST /api/v1/ingest/utility_anomaly and DELETE /api/v1/ingest/utility_anomaly/{id}
- POST /api/v1/ingest/lifecycle_asset and DELETE /api/v1/ingest/lifecycle_asset/{id}

Requests require Authorization: Bearer <subsystem token> and JSON for POST. UtilityTrack tokens can write utility records; LifeCycleTrack tokens can write lifecycle assets. Unknown payload fields are discarded. Deletes set a soft-delete marker; repeating a POST for the same source record updates its central copy.

See docs/API_CONTRACT.md for payload fields, examples, and status codes. Writes are synchronous, so Supabase or network errors return 503 to the sender.
