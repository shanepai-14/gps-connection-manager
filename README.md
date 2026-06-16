# GPS Connection Manager — Socket Pool Service

A PHP microservice that manages persistent TCP connections to GPS tracking servers. Instead of opening and closing a new socket for every GPS ping, it keeps connections alive in a pool and reuses them — reducing latency and load on the GPS server.

Communication between this service and the Laravel backend happens over a Unix domain socket (IPC), keeping the interface fast and local.

---

## How It Works

```
GPS Device → Laravel Backend → Unix Socket → Socket Pool Service → GPS Server (20.175.56.x:1401)
```

1. A GPS device sends a position update to the Laravel API (`POST /api/position`)
2. Laravel forwards the data to this service via the Unix socket
3. The service sends the data to the GPS tracking server over a reused TCP connection
4. The GPS server acknowledges with a 4-byte binary response (`0x00000001`)
5. The service returns the result to Laravel

---

## Requirements

- PHP >= 8.0
- PHP extensions: `sockets`, `pcntl`, `json`
- Composer
- Redis (optional, for metrics)
- Linux (required for Unix sockets and `pcntl`)

Check your extensions:
```bash
php -m | grep -E "sockets|pcntl|json"
```

---

## Installation

### Automated (recommended)

```bash
git clone <repo-url> /opt/gps-connection-manager
cd /opt/gps-connection-manager
chmod +x install.sh
sudo bash install.sh
```

The install script will:
- Install Composer dependencies
- Create required directories (`/var/run/socket-pool/`, `/var/log/socket-pool/`)
- Register and start a `systemd` service
- Set up log rotation (30-day retention)
- Apply system tuning (file descriptors, TCP settings)

### Manual

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env   # edit values as needed
mkdir -p logs
php bin/socket-pool start
```

---

## Configuration

Copy `.env.example` to `.env` and adjust:

```env
# Service
SOCKET_POOL_MAX_SIZE=100          # max pooled connections
SOCKET_POOL_TIMEOUT=30            # idle connection expiry (seconds)
SOCKET_POOL_MAX_RETRIES=3         # retries on connection failure
SOCKET_POOL_UNIX_PATH=/var/run/socket-pool/socket_pool_service.sock
SOCKET_POOL_LOG_LEVEL=INFO        # DEBUG, INFO, WARNING, ERROR
SOCKET_POOL_LOG_FILE=/var/log/socket-pool/socket_pool_service.log

# ACK timeout (how long to wait for GPS server acknowledgment)
SOCKET_POOL_ACK_TIMEOUT_MS=200    # milliseconds — GPS server normally responds in <1ms

# Redis (optional — for metrics and monitoring)
SOCKET_POOL_REDIS_ENABLED=false
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# Metrics
SOCKET_POOL_METRICS_ENABLED=true
SOCKET_POOL_HEALTH_INTERVAL=60
```

> **Note on `SOCKET_POOL_ACK_TIMEOUT_MS`:** The GPS server at the target host sends a binary ACK (`0x00000001`) within milliseconds under normal conditions. Under heavy load it may not send an ACK at all — this is a known behavior on their end. The service treats a missing ACK as a successful send (the data was delivered) and logs a warning instead of returning an error.

---

## Running the Service

### Via systemd (production)
```bash
sudo systemctl start socket-pool-service
sudo systemctl stop socket-pool-service
sudo systemctl restart socket-pool-service
sudo systemctl status socket-pool-service
```

### Via Composer scripts
```bash
composer start
composer stop
composer restart
composer status
```

### Via CLI directly
```bash
php bin/socket-pool start           # foreground
php bin/socket-pool start --daemon  # background (daemon mode)
php bin/socket-pool stop
php bin/socket-pool restart
php bin/socket-pool status
```

---

## Available Commands

| Command | Description |
|---|---|
| `start` | Start the service |
| `stop` | Stop the service |
| `restart` | Restart the service |
| `status` | Show current status and uptime |
| `stats` | Show pool statistics (active connections, hit/miss rate) |
| `health` | Run a health check |
| `monitor` | Live real-time monitoring |
| `install` | Install as system service (requires root) |
| `config` | View or update configuration |
| `test` | Send a test GPS message |
| `pool` | Pool management (list, close connections) |
| `cache:clear` | Clear cached connections |
| `backup` | Backup service data |

---

## Laravel Integration

The Laravel backend communicates with this service via `SocketPoolClient` (located in `src/Client/SocketPoolClient.php`). Copy or symlink this file into your Laravel project.

### Sending GPS data

```php
$client = new SocketPoolClient('/var/run/socket-pool/socket_pool_service.sock');

$result = $client->sendGpsData(
    host: '20.175.56.146',
    port: 1401,
    message: '$GPRMC,...',   // GPS NMEA string or tracker-specific format
    vehicleId: 'TRUCK-001'
);

// $result['success']      — true if message was sent
// $result['ack_received'] — true if GPS server acknowledged
// $result['bytes_sent']   — bytes written to GPS server
// $result['hex_response'] — raw ACK in hex (00000001 = normal ACK)
```

### Other available methods

```php
$client->getConnectionStats();   // pool hit/miss rates per host:port
$client->getMetrics();           // memory usage, pool size, uptime
$client->performHealthCheck();   // checks unix socket, redis, active connections
$client->closeConnection($host, $port);   // force-close a pooled connection
$client->isServiceRunning();     // returns bool
```

### Unix socket path

The Laravel client and this service must agree on the socket path. Set it in both:

- **This service:** `SOCKET_POOL_UNIX_PATH` in `.env`
- **Laravel:** constructor argument or `SOCKET_POOL_UNIX_PATH` env var

---

## Logs

| Log | Location |
|---|---|
| Service log | `SOCKET_POOL_LOG_FILE` in `.env` |
| Rotated logs | Same path, suffixed by date (e.g. `socket_pool_service-2026-06-17.log`) |
| systemd journal | `journalctl -u socket-pool-service -f` |

### Common log entries

| Level | Message | Meaning |
|---|---|---|
| `WARNING` | `GPS ACK not received within timeout` | GPS server didn't reply — data still delivered |
| `ERROR` | `Error sending GPS data` | Genuine send failure (connection refused, network error) |
| `WARNING` | `Failed to write response to client socket` | Laravel caller disconnected before response was sent |
| `INFO` | `Socket Pool Service started` | Service started successfully |

---

## Troubleshooting

**Service won't start — socket already in use**

```bash
sudo rm /var/run/socket-pool/socket_pool_service.sock
php bin/socket-pool start
```

**Laravel can't connect to the service**

```bash
php bin/socket-pool status          # is it running?
ls -la /var/run/socket-pool/        # does the socket file exist?
php bin/socket-pool health          # full health check
```

**GPS ACK warnings appearing in logs during peak hours (11am–12pm)**

Expected behavior — this is a known limitation on the GPS server side under load. The service handles it gracefully and the data is still delivered. If the warnings are excessive, the GPS server developer should be notified. See the bug report template below.

**Redis connection failing**

Set `SOCKET_POOL_REDIS_ENABLED=false` in `.env` to disable Redis. The service runs fully without it — Redis is only used for metrics.

---

## Reporting Issues to the GPS Server Developer

If GPS ACK warnings increase significantly, provide the server developer with:

- **Host/Port:** `20.175.56.146:1401`
- **Issue:** Server sends no ACK for some connections under concurrent load
- **Evidence:** Normal ACK round-trip is `< 1ms`. Missing ACK means the server accepted the TCP connection and data but did not send `0x00000001` back
- **Pattern:** Occurs during peak concurrent connections (typically 11am–12pm)
- **Ask:** Confirm whether ACK is guaranteed per message per their protocol spec, and check their server's write path under concurrent load

---

## Project Structure

```
├── bin/
│   └── socket-pool          # CLI entry point
├── src/
│   ├── Client/
│   │   └── SocketPoolClient.php    # Laravel client library
│   ├── Console/             # CLI commands (start, stop, stats, etc.)
│   ├── Exceptions/          # SocketPoolException, ConnectionException
│   └── Services/
│       └── SocketPoolService.php   # core service
├── logs/                    # rotating log files
├── install.sh               # automated setup script
├── composer.json
└── .env                     # configuration
```
