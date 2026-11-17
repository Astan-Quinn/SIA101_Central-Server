# SIA101 Central LAN Certificate Verification Inventory

Last updated: 2026-11-12 (Asia/Taipei)

This inventory records checks reported from the named computers. A Windows
certificate-store check and `curl.exe` health request are workstation checks;
they do not replace the application-specific PHP or synchronization checks.
No tokens, passwords, or private keys are recorded here.

## Workstations

| Subsystem | Hostname | IPv4 | Root CA store check | HTTPS / application checks | Status and follow-up |
|---|---|---:|---|---|---|
| Central Server | `DESKTOP-OLVT1R2` | `192.168.1.11` | SHA-1 thumbprint `4A429AA5F3CB52219DA6A13307800DBAD72DE025` found in Current User and Local Machine Trusted Root stores. | Leaf subject `CN=192.168.1.11`, issuer `CN=Central LAN Root CA`, SAN `IP Address=192.168.1.11`; valid 2026-11-11 through 2029-02-13. `certutil -verify -urlfetch` passed, including leaf revocation check. HTTPS health returned 200. | Pass. CRL `NextUpdate` reported as 2026-11-18; renew and republish before expiry. |
| LifeCycleTrack | `LAPTOP-FO5MB6BA` | `192.168.1.14` | Root CA found in Local Machine store; not found in Current User store. | Windows `curl.exe` health returned 200. PHP CLI uses `C:\xampp\php\php.ini`; both `curl.cainfo` and `openssl.cafile` were set to `C:\Users\Public\curl-ca-bundle-with-central.crt`. PHP cURL health returned HTTP 200 with no error. Earlier Windows curl output showed TLS renegotiation messages; Apache has `SSLOptions +StdEnvVars` for PHP files. | TLS pass. Review renegotiation during Apache TLS hardening. Lifecycle outbox publisher success after the HTTPS root URL correction is not yet confirmed; prior outbox screenshot showed HTTP 404. |
| UtilityTrack | `LAPTOP-0KE0QAPE` | `192.168.1.13` | Root CA found in Local Machine store; not found in Current User store. | Windows `curl.exe` health returned 200. PHP cURL to Central returned HTTP 200 when explicitly given the Central CA file. A UtilityTrack bill (`source_id=40`) appeared in Central `central_utility_bills`. Combined bundle created at `C:\Users\Aaron\curl-ca-bundle-with-central.crt`. | Bill sync pass. Login after configuring `CURL_CAINFO` to the combined bundle has not been confirmed. Confirm `CENTRAL_API_BASE_URL` uses HTTPS and the CA setting points to the combined public-plus-Central bundle. |
| MaintainTrack | `DESKTOP-KPTQRA5` | `192.168.1.16` | Root CA found in Local Machine store; not found in Current User store. | Windows `curl.exe` health returned 200. PHP CLI uses `C:\xampp\php\php.ini` with `curl.cainfo` and `openssl.cafile` set to `C:\Users\Public\curl-ca-bundle-with-central.crt`. After this fix, the worker processed event `e4782c66-633d-4bdb-9fe3-0b1c17d7968c` with result `applied`. An unauthenticated inbox read returned 401 as expected. | Pass for Windows and PHP HTTPS, authenticated inbox processing, and unauthenticated rejection. |

## Certificate and revocation facts

- Central LAN Root CA SHA-1 thumbprint: `4A429AA5F3CB52219DA6A13307800DBAD72DE025`.
- Root CA subject: `CN=Central LAN Root CA`; reported expiration: 2036-11-08.
- Central server leaf certificate SAN: `IP Address=192.168.1.11`.
- Central server leaf SHA-256 fingerprint: `AF:67:04:23:29:20:01:39:21:F9:D2:5A:6A:9C:72:6B:BB:5D:28:CF:51:26:C3:F0:14:56:3A:71:97:00:49:5D`.
- CRL URL: `http://192.168.1.11/pki/central-root-ca.crl`.
- CRL issuer matched the CA; Central's `certutil -verify -urlfetch` reported the leaf revocation check passed. Reported CRL `NextUpdate`: 2026-11-18.
- The root CA private key is not distributed to clients and is not included in this inventory.

## Remaining verification

1. Confirm the LifeCycleTrack outbox publisher succeeds after setting its Central base URL to `https://192.168.1.11` and using the corrected Apache document root.
2. Confirm UtilityTrack login works after its shared `CURL_CAINFO` points to a combined bundle containing both XAMPP public roots and the Central LAN root.
3. Verify the UtilityTrack anomaly flow separately from the bill flow.
4. Review Apache TLS renegotiation and effective TLS protocol/cipher settings before deployment.
5. Renew and republish the CRL before 2026-11-18, then rerun certificate-chain and revocation checks.
