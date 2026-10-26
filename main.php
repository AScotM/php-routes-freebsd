#!/usr/bin/env php
<?php
declare(strict_types=1);

final class Settings
{
    public int $interval = 2;
    public int $limit = 50000;
    public int $historyLimit = 1000;
    public int $timeout = 12;
    public int $cacheTtl = 1;
    public bool $ipv4 = true;
    public bool $ipv6 = true;
    public bool $processes = true;
    public bool $colors = true;
    public bool $watch = false;
    public bool $stats = false;
    public bool $changes = false;
    public bool $listenOnly = false;
    public bool $noListen = false;
    public string $format = 'table';
    public ?string $state = null;
    public ?string $address = null;
    public ?string $cidr = null;
    public ?int $port = null;
    public ?int $pid = null;
    public ?string $process = null;
    public ?string $output = null;
    public ?int $count = null;

    public static function parse(array $argv): self
    {
        $s = new self();
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if (in_array($arg, ['-h', '--help'], true)) {
                self::help();
                exit(0);
            }
            if (in_array($arg, ['-4', '--ipv4'], true)) { $s->ipv4 = true; $s->ipv6 = false; continue; }
            if (in_array($arg, ['-6', '--ipv6'], true)) { $s->ipv4 = false; $s->ipv6 = true; continue; }
            if ($arg === '--no-process') { $s->processes = false; continue; }
            if ($arg === '--no-color') { $s->colors = false; continue; }
            if ($arg === '--watch' || $arg === '-w') { $s->watch = true; continue; }
            if ($arg === '--stats') { $s->stats = true; continue; }
            if ($arg === '--changes') { $s->changes = true; $s->watch = true; continue; }
            if ($arg === '--listen') { $s->listenOnly = true; continue; }
            if ($arg === '--no-listen') { $s->noListen = true; continue; }
            $parts = explode('=', $arg, 2);
            $name = $parts[0];
            $takesValue = ['--interval', '--limit', '--timeout', '--cache-ttl', '--history-limit', '--format', '--state', '--address', '--cidr', '--port', '--pid', '--process', '--output', '--count'];
            if (!in_array($name, $takesValue, true)) { throw new InvalidArgumentException('Unknown option: ' . $arg); }
            $value = $parts[1] ?? null;
            if ($value === null) {
                $value = $argv[++$i] ?? null;
            }
            if ($value === null || $value === '') { throw new InvalidArgumentException('Missing value for ' . $name); }
            switch ($name) {
                case '--interval': $s->interval = self::number($value, 1, 3600); break;
                case '--limit': $s->limit = self::number($value, 1, 1000000); break;
                case '--timeout': $s->timeout = self::number($value, 1, 120); break;
                case '--cache-ttl': $s->cacheTtl = self::number($value, 0, 3600); break;
                case '--history-limit': $s->historyLimit = self::number($value, 1, 100000); break;
                case '--port': $s->port = self::number($value, 0, 65535); break;
                case '--pid': $s->pid = self::number($value, 1, 2147483647); break;
                case '--count': $s->count = self::number($value, 1, 1000000); break;
                case '--format':
                    if (!in_array($value, ['table', 'json', 'csv'], true)) { throw new InvalidArgumentException('Invalid format'); }
                    $s->format = $value;
                    break;
                case '--state': $s->state = strtoupper(str_replace('-', '_', $value)); break;
                case '--address': $s->address = $value; break;
                case '--cidr':
                    if (!Network::validCidr($value)) { throw new InvalidArgumentException('Invalid CIDR'); }
                    $s->cidr = $value;
                    break;
                case '--process': $s->process = $value; break;
                case '--output': $s->output = $value; break;
            }
        }
        if ($s->listenOnly && $s->noListen) { throw new InvalidArgumentException('Conflicting listen filters'); }
        if ($s->changes && $s->format === 'csv') { throw new InvalidArgumentException('Change monitoring does not support CSV'); }
        return $s;
    }

    private static function number(string $value, int $min, int $max): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT);
        if ($n === false || $n < $min || $n > $max) { throw new InvalidArgumentException('Invalid integer: ' . $value); }
        return $n;
    }

    public static function help(): void
    {
        echo <<<'HELP'
php-routes-freebsd

Usage: php main.php [options]

  -4, --ipv4               IPv4 only
  -6, --ipv6               IPv6 only
  -w, --watch              Continuous monitoring
  --changes                Report connection changes while watching
  --interval N             Watch interval in seconds
  --count N                Maximum number of watch iterations
  --format table|json|csv  Output format
  --output FILE            Write output to a file
  --stats                  Include statistics
  --state STATE            Filter by TCP state
  --port N                 Filter by local or remote port
  --address TEXT           Filter by address substring
  --cidr NETWORK/MASK      Filter by IPv4 or IPv6 network
  --pid N                  Filter by process ID
  --process TEXT           Filter by process name substring
  --listen                 Show listening sockets only
  --no-listen              Exclude listening sockets
  --no-process             Disable process attribution
  --no-color               Disable terminal colors
  --limit N                Maximum connections per snapshot
  --timeout N              Command timeout in seconds
  --cache-ttl N            Snapshot cache lifetime in seconds
  --history-limit N        Maximum tracked change events
  -h, --help               Show this help

Requires FreeBSD, PHP 8.1+, sockstat and netstat.

HELP;
    }
}

final class Logger
{
    public static function write(string $level, string $message): void
    {
        fwrite(STDERR, sprintf("[%s] [%s] %s\n", date('c'), $level, preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '?', $message)));
    }
}

final class RuntimeMetrics
{
    private float $started;
    private array $timers = [];
    private array $counters = [];

    public function __construct() { $this->started = microtime(true); }
    public function increment(string $key, int $amount = 1): void { $this->counters[$key] = ($this->counters[$key] ?? 0) + $amount; }
    public function begin(string $key): void { $this->timers[$key]['started'] = microtime(true); }
    public function end(string $key): void
    {
        $t = &$this->timers[$key];
        if (!isset($t['started'])) { return; }
        $t['seconds'] = ($t['seconds'] ?? 0.0) + microtime(true) - $t['started'];
        $t['calls'] = ($t['calls'] ?? 0) + 1;
        unset($t['started']);
    }
    public function report(): array
    {
        $timings = [];
        foreach ($this->timers as $key => $value) {
            $timings[$key] = ['seconds' => round($value['seconds'] ?? 0, 5), 'calls' => $value['calls'] ?? 0];
        }
        return ['uptime_seconds' => round(microtime(true) - $this->started, 3), 'memory_bytes' => memory_get_usage(true), 'peak_memory_bytes' => memory_get_peak_usage(true), 'counters' => $this->counters, 'timings' => $timings];
    }
}

final class CommandRunner
{
    public function __construct(private int $timeout) {}

    public function run(array $command): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($command, $descriptors, $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/sbin:/usr/local/bin'], ['bypass_shell' => true]);
        if (!is_resource($proc)) { throw new RuntimeException('Cannot start ' . $command[0]); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $started = microtime(true);
        $exit = null;
        $lastStatus = null;
        try {
            while (true) {
                $read = [];
                foreach ([1, 2] as $index) {
                    if (is_resource($pipes[$index]) && !feof($pipes[$index])) { $read[] = $pipes[$index]; }
                }
                if ($read) {
                    $write = null;
                    $except = null;
                    @stream_select($read, $write, $except, 0, 100000);
                    foreach ($read as $stream) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) { continue; }
                        if ($stream === $pipes[1]) { $out .= $chunk; } else { $err .= $chunk; }
                    }
                } else { usleep(20000); }
                if (strlen($out) + strlen($err) > 33554432) { throw new RuntimeException('Command output exceeded 32 MiB'); }
                if (microtime(true) - $started > $this->timeout) { throw new RuntimeException('Command timed out: ' . $command[0]); }
                $lastStatus = proc_get_status($proc);
                if (!$lastStatus['running']) { $exit = $lastStatus['exitcode']; break; }
            }
            foreach ([1, 2] as $index) {
                $remaining = stream_get_contents($pipes[$index]);
                if ($remaining !== false) {
                    if ($index === 1) { $out .= $remaining; } else { $err .= $remaining; }
                }
            }
        } catch (Throwable $e) {
            proc_terminate($proc);
            usleep(100000);
            $status = proc_get_status($proc);
            if ($status['running']) { proc_terminate($proc, 9); }
            throw $e;
        } finally {
            foreach ([1, 2] as $index) { if (is_resource($pipes[$index])) { fclose($pipes[$index]); } }
            $closed = proc_close($proc);
        }
        if ($exit === null || $exit < 0) { $exit = $closed; }
        if ($exit !== 0) { throw new RuntimeException($command[0] . ' exited with ' . $exit . ': ' . trim($err)); }
        return $out;
    }
}

final class Network
{
    public static function normalizeAddress(string $ip): string
    {
        $ip = trim($ip, '[]');
        $ip = preg_replace('/%[a-zA-Z0-9_.-]+$/', '', $ip) ?? $ip;
        if ($ip === '*' || $ip === '') { return '*'; }
        $packed = @inet_pton($ip);
        return $packed === false ? strtolower($ip) : strtolower((string)inet_ntop($packed));
    }

    public static function endpoint(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') { return null; }
        if (preg_match('/^\[(.*)\]:(\d+|\*)$/', $value, $m)) {
            return ['ip' => self::normalizeAddress($m[1]), 'port' => $m[2] === '*' ? 0 : (int)$m[2]];
        }
        $pos = strrpos($value, ':');
        if ($pos === false || !preg_match('/^(\d+|\*)$/', substr($value, $pos + 1))) {
            $pos = strrpos($value, '.');
        }
        if ($pos === false) { return null; }
        $port = substr($value, $pos + 1);
        if (!preg_match('/^(\d+|\*)$/', $port)) { return null; }
        $ip = substr($value, 0, $pos);
        return ['ip' => self::normalizeAddress($ip), 'port' => $port === '*' ? 0 : (int)$port];
    }

    public static function validCidr(string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false || !ctype_digit($parts[1])) { return false; }
        $max = str_contains($parts[0], ':') ? 128 : 32;
        return (int)$parts[1] <= $max;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        if (!self::validCidr($cidr)) { return false; }
        [$subnet, $prefix] = explode('/', $cidr);
        $a = @inet_pton(self::normalizeAddress($ip));
        $b = @inet_pton($subnet);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) { return false; }
        $bits = (int)$prefix;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) { return false; }
        $remainder = $bits % 8;
        if ($remainder === 0) { return true; }
        $mask = (0xff << (8 - $remainder)) & 0xff;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    public static function key(array $c, bool $reverse = false): string
    {
        $a = $reverse ? 'remote' : 'local';
        $b = $reverse ? 'local' : 'remote';
        return implode('|', [$c['family'], $c[$a . '_ip'], $c[$a . '_port'], $c[$b . '_ip'], $c[$b . '_port']]);
    }

    public static function display(string $ip, int $port): string
    {
        return str_contains($ip, ':') ? '[' . $ip . ']:' . $port : $ip . ':' . $port;
    }
}

final class SocketParser
{
    public static function parse(string $text, int $limit): array
    {
        $result = [];
        $lines = preg_split('/\r?\n/', $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^USER\s+COMMAND\s+PID\s+FD\s+PROTO\b/i', $line)) { continue; }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 7) { continue; }
            $protoIndex = null;
            foreach ($parts as $index => $part) {
                if (preg_match('/^tcp[46]?$/i', $part)) { $protoIndex = $index; break; }
            }
            if ($protoIndex === null || $protoIndex < 4 || !isset($parts[$protoIndex + 1], $parts[$protoIndex + 2])) { continue; }
            $local = Network::endpoint($parts[$protoIndex + 1]);
            $remote = Network::endpoint($parts[$protoIndex + 2]);
            if ($local === null || $remote === null) { continue; }
            $proto = strtolower($parts[$protoIndex]);
            $family = $proto === 'tcp6' || str_contains($local['ip'], ':') || str_contains($remote['ip'], ':') ? 'IPv6' : 'IPv4';
            $pid = ctype_digit($parts[2]) ? (int)$parts[2] : null;
            $result[] = [
                'family' => $family, 'state' => 'UNKNOWN',
                'local_ip' => $local['ip'], 'local_port' => $local['port'],
                'remote_ip' => $remote['ip'], 'remote_port' => $remote['port'],
                'process' => $parts[1], 'user' => $parts[0], 'pid' => $pid,
                'fd' => $parts[3], 'source' => 'sockstat', 'timestamp' => date('c')
            ];
            if (count($result) >= $limit) { break; }
        }
        return $result;
    }
}

final class StateParser
{
    private const STATES = ['LISTEN', 'ESTABLISHED', 'SYN_SENT', 'SYN_RECEIVED', 'SYN_RECV', 'FIN_WAIT_1', 'FIN_WAIT_2', 'FIN_WAIT1', 'FIN_WAIT2', 'TIME_WAIT', 'CLOSE_WAIT', 'CLOSING', 'LAST_ACK', 'CLOSED'];

    public static function parse(string $text, int $limit): array
    {
        $result = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if (!preg_match('/^tcp[46]?\s+/i', $line)) { continue; }
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 5) { continue; }
            $state = strtoupper(str_replace('-', '_', end($parts)));
            if (!in_array($state, self::STATES, true)) { continue; }
            $proto = strtolower($parts[0]);
            $family = $proto === 'tcp6' ? 'IPv6' : 'IPv4';
            $local = null;
            $remote = null;
            for ($i = 1; $i < count($parts) - 2; $i++) {
                $candidate = Network::endpoint($parts[$i]);
                $next = Network::endpoint($parts[$i + 1]);
                if ($candidate !== null && $next !== null) { $local = $candidate; $remote = $next; break; }
            }
            if ($local === null || $remote === null) { continue; }
            if ($proto === 'tcp' && (str_contains($local['ip'], ':') || str_contains($remote['ip'], ':'))) { $family = 'IPv6'; }
            $result[] = [
                'family' => $family, 'state' => $state,
                'local_ip' => $local['ip'], 'local_port' => $local['port'],
                'remote_ip' => $remote['ip'], 'remote_port' => $remote['port'],
                'process' => '', 'user' => '', 'pid' => null, 'fd' => '',
                'source' => 'netstat', 'timestamp' => date('c')
            ];
            if (count($result) >= $limit) { break; }
        }
        return $result;
    }
}

final class Correlator
{
    public static function merge(array $states, array $owners): array
    {
        $index = [];
        foreach ($owners as $owner) {
            $key = Network::key($owner);
            $index[$key][] = $owner;
        }
        $out = [];
        $matched = [];
        foreach ($states as $state) {
            $key = Network::key($state);
            $candidates = $index[$key] ?? [];
            if (!$candidates && $state['state'] === 'LISTEN') {
                $wildcard = $state;
                $wildcard['remote_ip'] = '*';
                $wildcard['remote_port'] = 0;
                $candidates = $index[Network::key($wildcard)] ?? [];
            }
            if (!$candidates) { $out[] = $state; continue; }
            foreach ($candidates as $owner) {
                $matched[Network::key($owner) . '|' . ($owner['pid'] ?? '') . '|' . $owner['fd']] = true;
                $merged = $state;
                foreach (['process', 'user', 'pid', 'fd'] as $field) { $merged[$field] = $owner[$field]; }
                $merged['source'] = 'correlated';
                $out[] = $merged;
            }
        }
        foreach ($owners as $owner) {
            $id = Network::key($owner) . '|' . ($owner['pid'] ?? '') . '|' . $owner['fd'];
            if (!isset($matched[$id])) { $out[] = $owner; }
        }
        return $out;
    }
}

final class SnapshotCollector
{
    private array $cached = [];
    private float $last = 0;

    public function __construct(private Settings $settings, private CommandRunner $runner, private RuntimeMetrics $metrics) {}

    public function collect(): array
    {
        if ($this->cached && microtime(true) - $this->last < $this->settings->cacheTtl) {
            $this->metrics->increment('cache_hits');
            return $this->cached;
        }
        $this->metrics->begin('collection');
        try {
            $states = [];
            $owners = [];
            $families = [];
            if ($this->settings->ipv4) { $families[] = 'inet'; }
            if ($this->settings->ipv6) { $families[] = 'inet6'; }
            foreach ($families as $family) {
                try {
                    $text = $this->runner->run(['/usr/bin/netstat', '-an', '-f', $family, '-p', 'tcp']);
                    $states = array_merge($states, StateParser::parse($text, $this->settings->limit));
                } catch (Throwable $e) { Logger::write('WARNING', $e->getMessage()); }
            }
            if ($this->settings->processes) {
                $args = ['/usr/bin/sockstat'];
                if ($this->settings->ipv4) { $args[] = '-4'; }
                if ($this->settings->ipv6) { $args[] = '-6'; }
                $args[] = '-P';
                $args[] = 'tcp';
                try {
                    $owners = SocketParser::parse($this->runner->run($args), $this->settings->limit);
                } catch (Throwable $e) { Logger::write('WARNING', $e->getMessage()); }
            }
            $this->cached = array_slice(Correlator::merge($states, $owners), 0, $this->settings->limit);
            $this->last = microtime(true);
            $this->metrics->increment('snapshots');
            $this->metrics->increment('connections_observed', count($this->cached));
            return $this->cached;
        } finally { $this->metrics->end('collection'); }
    }
}

final class ConnectionFilter
{
    public static function apply(array $connections, Settings $s): array
    {
        return array_values(array_filter($connections, static function (array $c) use ($s): bool {
            if ($s->ipv4 !== $s->ipv6 && $c['family'] !== ($s->ipv4 ? 'IPv4' : 'IPv6')) { return false; }
            if ($s->state !== null && strtoupper($c['state']) !== $s->state) { return false; }
            if ($s->listenOnly && $c['state'] !== 'LISTEN') { return false; }
            if ($s->noListen && $c['state'] === 'LISTEN') { return false; }
            if ($s->port !== null && $c['local_port'] !== $s->port && $c['remote_port'] !== $s->port) { return false; }
            if ($s->pid !== null && $c['pid'] !== $s->pid) { return false; }
            if ($s->process !== null && stripos($c['process'], $s->process) === false) { return false; }
            if ($s->address !== null && stripos($c['local_ip'], $s->address) === false && stripos($c['remote_ip'], $s->address) === false) { return false; }
            if ($s->cidr !== null && !Network::inCidr($c['local_ip'], $s->cidr) && !Network::inCidr($c['remote_ip'], $s->cidr)) { return false; }
            return true;
        }));
    }
}

final class Statistics
{
    public static function build(array $connections): array
    {
        $states = [];
        $processes = [];
        $families = ['IPv4' => 0, 'IPv6' => 0];
        $attributed = 0;
        foreach ($connections as $c) {
            $states[$c['state']] = ($states[$c['state']] ?? 0) + 1;
            $families[$c['family']] = ($families[$c['family']] ?? 0) + 1;
            if ($c['pid'] !== null) {
                $attributed++;
                $name = $c['process'] . ' (PID ' . $c['pid'] . ')';
                $processes[$name] = ($processes[$name] ?? 0) + 1;
            }
        }
        arsort($states);
        arsort($processes);
        return ['total' => count($connections), 'families' => $families, 'states' => $states, 'attributed' => $attributed, 'processes' => array_slice($processes, 0, 10, true), 'generated_at' => date('c')];
    }
}

final class History
{
    private array $previous = [];
    private array $events = [];
    private bool $initialized = false;

    public function __construct(private int $limit) {}

    public function update(array $connections): array
    {
        $current = [];
        foreach ($connections as $c) {
            $key = Network::key($c) . '|' . $c['state'] . '|' . ($c['pid'] ?? '');
            $current[$key] = $c;
        }
        if (!$this->initialized) {
            $this->previous = $current;
            $this->initialized = true;
            return [];
        }
        $changes = [];
        foreach (array_diff_key($current, $this->previous) as $c) { $changes[] = ['event' => 'appeared', 'connection' => $c, 'at' => date('c')]; }
        foreach (array_diff_key($this->previous, $current) as $c) { $changes[] = ['event' => 'disappeared', 'connection' => $c, 'at' => date('c')]; }
        $this->previous = $current;
        foreach ($changes as $change) { $this->events[] = $change; }
        if (count($this->events) > $this->limit) { $this->events = array_slice($this->events, -$this->limit); }
        return $changes;
    }

    public function summary(): array
    {
        $counts = ['appeared' => 0, 'disappeared' => 0];
        foreach ($this->events as $event) { $counts[$event['event']]++; }
        return ['events_retained' => count($this->events), 'counts' => $counts];
    }
}

final class Formatter
{
    private const COLORS = ['LISTEN' => "\033[32m", 'ESTABLISHED' => "\033[36m", 'TIME_WAIT' => "\033[33m", 'CLOSE_WAIT' => "\033[31m", 'SYN_SENT' => "\033[35m", 'SYN_RECEIVED' => "\033[35m"];

    public static function render(array $connections, Settings $s, RuntimeMetrics $metrics, ?array $events = null): string
    {
        usort($connections, static fn(array $a, array $b): int => [$a['local_port'], $a['family'], $a['local_ip']] <=> [$b['local_port'], $b['family'], $b['local_ip']]);
        $stats = Statistics::build($connections);
        if ($s->format === 'json') {
            $data = ['platform' => 'FreeBSD', 'connections' => $connections];
            if ($s->stats) { $data['statistics'] = $stats; $data['runtime'] = $metrics->report(); }
            if ($events !== null) { $data['events'] = $events; }
            return json_encode($data, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($s->format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) { throw new RuntimeException('Cannot allocate CSV stream'); }
            fputcsv($stream, ['family', 'state', 'local_ip', 'local_port', 'remote_ip', 'remote_port', 'process', 'pid', 'user', 'fd', 'source']);
            foreach ($connections as $c) {
                $row = [];
                foreach (['family', 'state', 'local_ip', 'local_port', 'remote_ip', 'remote_port', 'process', 'pid', 'user', 'fd', 'source'] as $key) {
                    $value = $c[$key] ?? '';
                    $row[] = is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value;
                }
                fputcsv($stream, $row);
            }
            rewind($stream);
            $text = stream_get_contents($stream);
            fclose($stream);
            return $text === false ? '' : $text;
        }
        $out = "\nFREEBSD TCP CONNECTIONS  " . date('Y-m-d H:i:s') . "\n";
        $out .= str_repeat('=', 132) . "\n";
        $out .= sprintf("%-5s %-15s %-38s %-38s %-24s %-7s\n", 'IP', 'STATE', 'LOCAL', 'REMOTE', 'PROCESS', 'PID');
        $out .= str_repeat('-', 132) . "\n";
        foreach ($connections as $c) {
            $color = $s->colors && $s->format === 'table' ? (self::COLORS[$c['state']] ?? '') : '';
            $reset = $color !== '' ? "\033[0m" : '';
            $state = $color . str_pad($c['state'], 15) . $reset;
            $out .= sprintf("%-5s %s %-38.38s %-38.38s %-24.24s %-7s\n", $c['family'] === 'IPv4' ? 'IPv4' : 'IPv6', $state, Network::display($c['local_ip'], $c['local_port']), Network::display($c['remote_ip'], $c['remote_port']), $c['process'] ?: '-', $c['pid'] === null ? '-' : (string)$c['pid']);
        }
        $out .= sprintf("\nTotal: %d  IPv4: %d  IPv6: %d  Attributed: %d\n", $stats['total'], $stats['families']['IPv4'], $stats['families']['IPv6'], $stats['attributed']);
        if ($s->stats) {
            $out .= "\nSTATES\n";
            foreach ($stats['states'] as $name => $count) { $out .= sprintf("  %-22s %d\n", $name, $count); }
            $out .= "\nTOP PROCESSES\n";
            foreach ($stats['processes'] as $name => $count) { $out .= sprintf("  %-38s %d\n", $name, $count); }
            $out .= "\nRUNTIME\n" . json_encode($metrics->report(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        }
        if ($events !== null) {
            $out .= "\nCHANGES\n";
            foreach ($events as $event) {
                $c = $event['connection'];
                $out .= sprintf("  %-12s %-14s %-38s -> %s\n", $event['event'], $c['state'], Network::display($c['local_ip'], $c['local_port']), Network::display($c['remote_ip'], $c['remote_port']));
            }
        }
        return $out;
    }
}

final class OutputSink
{
    public static function write(string $text, ?string $file): void
    {
        if ($file === null) { echo $text; return; }
        $handle = @fopen($file, 'ab');
        if ($handle === false) { throw new RuntimeException('Cannot open output file: ' . $file); }
        try {
            if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Cannot lock output file'); }
            $length = strlen($text);
            $offset = 0;
            while ($offset < $length) {
                $n = fwrite($handle, substr($text, $offset));
                if ($n === false || $n === 0) { throw new RuntimeException('Cannot write output file'); }
                $offset += $n;
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally { fclose($handle); }
    }
}

final class Application
{
    private bool $running = true;

    public function run(array $argv): int
    {
        $s = Settings::parse($argv);
        if (PHP_OS_FAMILY !== 'BSD' || stripos(php_uname('s'), 'FreeBSD') === false) { throw new RuntimeException('This implementation requires FreeBSD'); }
        if (PHP_VERSION_ID < 80100) { throw new RuntimeException('PHP 8.1 or newer is required'); }
        if (!function_exists('proc_open')) { throw new RuntimeException('proc_open must be enabled'); }
        $metrics = new RuntimeMetrics();
        $runner = new CommandRunner($s->timeout);
        $collector = new SnapshotCollector($s, $runner, $metrics);
        $history = new History($s->historyLimit);
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void { $this->running = false; });
            pcntl_signal(SIGTERM, function (): void { $this->running = false; });
        }
        $iteration = 0;
        do {
            $started = microtime(true);
            $connections = ConnectionFilter::apply($collector->collect(), $s);
            $events = $s->changes ? $history->update($connections) : null;
            $text = Formatter::render($connections, $s, $metrics, $events);
            OutputSink::write($text, $s->output);
            $iteration++;
            if (!$s->watch || !$this->running || ($s->count !== null && $iteration >= $s->count)) { break; }
            $remaining = max(0, $s->interval - (microtime(true) - $started));
            while ($this->running && $remaining > 0) {
                $sleep = min($remaining, 0.2);
                usleep((int)($sleep * 1000000));
                $remaining -= $sleep;
            }
        } while ($this->running);
        return 0;
    }
}

try {
    exit((new Application())->run($argv));
} catch (Throwable $e) {
    Logger::write('ERROR', $e->getMessage());
    exit(1);
}
