<?php
namespace Koyabu\DropboxApi;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Dropbox - Koyabu\DropboxApi
* - OAuth2 Authorization Code Flow
* - Refresh Access Token otomatis
* - Membuat folder
* - Upload file kecil (upload langsung)
* - Upload file besar (chunked / session upload)
* - Download File
* - Hapus File
* - List folder
* - Create/Get/Delete/Modify Share Link
* - Get file info from Share Link URL
* - Penanganan error terpusat
 */
class Dropbox
{
    protected $access_token = '';
    protected $refresh_token = '';

    protected $app_key = '';
    protected $app_secret = '';

    protected $options = [];
    protected $scope = 'files.metadata.write files.content.write files.content.read sharing.write file_requests.write';

    /** @var Client */
    protected $api;
    /** @var Client */
    protected $content;

    protected $home_dir = '/';
    protected $redirect_url = '';

    public $error = '';

    protected $chunkRetries = 3;
    protected $chunkRetryDelay = 1; // seconds

    public function __construct(array $options)
    {
        if (!is_array($options)) {
            throw new Exception('Options must be an array');
        }

        if (empty($options['app_key']) || empty($options['app_secret'])) {
            throw new Exception('App key and app secret are required');
        }

        $this->options = $options;
        $this->app_key = $options['app_key'];
        $this->app_secret = $options['app_secret'];
        $this->scope = $options['scope'] ?? $this->scope;
        $this->redirect_url = $options['redirect_url'] ?? '';

        $this->home_dir = $this->normalizeDir($options['home_dir'] ?? '/');

        $this->api = new Client(['base_uri' => 'https://api.dropboxapi.com', 'timeout' => 60]);
        $this->content = new Client(['base_uri' => 'https://content.dropboxapi.com', 'timeout' => 120]);

        if (!empty($options['access_token'])) {
            $this->access_token = $options['access_token'];
        }

        if (!empty($options['refresh_token'])) {
            $this->refresh_token = $options['refresh_token'];
            if (!empty($options['auto_refresh']) && $options['auto_refresh'] === true) {
                $this->refreshToken($this->refresh_token);
            }
        }

        if (!empty($options['chunk_retries'])) $this->chunkRetries = (int)$options['chunk_retries'];
        if (!empty($options['chunk_retry_delay'])) $this->chunkRetryDelay = (int)$options['chunk_retry_delay'];
    }

    /* ------------------ Helpers ------------------ */
    protected function setError(string $msg)
    {
        $this->error = $msg;
        return false;
    }

    public function getLastError(): string
    {
        return $this->error;
    }

    public function getAccessTokenValue(): string
    {
        return $this->access_token;
    }

    public function getRefreshTokenValue(): string
    {
        return $this->refresh_token;
    }

    protected function normalizeDir(string $path): string
    {
        if ($path === '' || $path === '/') return '/';
        $p = '/' . ltrim($path, '/');
        return rtrim($p, '/') . '/';
    }

    protected function ensureAccessTokenAvailable(): bool
    {
        if (empty($this->access_token)) {
            $this->setError('No access token available');
            return false;
        }
        return true;
    }

    protected function jsonRequest(Client $client, string $method, string $uri, array $options = [])
    {
        try {
            $response = $client->request($method, $uri, $options);
            $body = (string)$response->getBody();
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
            return ['raw' => $body];
        } catch (RequestException $e) {
            $msg = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            return $this->setError($msg);
        } catch (Exception $e) {
            return $this->setError($e->getMessage());
        }
    }

    /* ------------------ Auth / Token ------------------ */
    public function getAuthUrl(string $state = '', array $extra = []): string
    {
        $url = 'https://www.dropbox.com/oauth2/authorize'
            . '?client_id=' . rawurlencode($this->app_key)
            . '&response_type=code'
            . '&token_access_type=offline'
            . '&scope=' . rawurlencode($this->scope);

        if (!empty($this->redirect_url)) $url .= '&redirect_uri=' . rawurlencode($this->redirect_url);
        if ($state) $url .= '&state=' . rawurlencode($state);
        foreach ($extra as $k => $v) $url .= '&' . rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        return $url;
    }

    public function getAccessToken(string $code)
    {
        $params = ['code' => $code, 'grant_type' => 'authorization_code'];
        if (!empty($this->redirect_url)) $params['redirect_uri'] = $this->redirect_url;

        $res = $this->jsonRequest($this->api, 'POST', '/oauth2/token', [
            'auth' => [$this->app_key, $this->app_secret],
            'form_params' => $params,
            'headers' => ['Accept' => 'application/json']
        ]);

        if ($res === false) return false;
        if (!empty($res['access_token'])) $this->access_token = $res['access_token'];
        if (!empty($res['refresh_token'])) $this->refresh_token = $res['refresh_token'];
        return $res;
    }

    public function refreshToken(string $refresh_token)
    {
        $params = ['refresh_token' => $refresh_token, 'grant_type' => 'refresh_token'];
        if (!empty($this->redirect_url)) $params['redirect_uri'] = $this->redirect_url;

        $res = $this->jsonRequest($this->api, 'POST', '/oauth2/token', [
            'auth' => [$this->app_key, $this->app_secret],
            'form_params' => $params,
            'headers' => ['Accept' => 'application/json']
        ]);

        if ($res === false) return false;
        if (!empty($res['access_token'])) $this->access_token = $res['access_token'];
        if (!empty($res['refresh_token'])) $this->refresh_token = $res['refresh_token'];
        return $res;
    }

    /* ------------------ File Operations ------------------ */
    public function createFolder(string $path, bool $autorename = false)
    {
        if (!$this->ensureAccessTokenAvailable()) return false;
        $payload = ['path' => $path, 'autorename' => $autorename];
        return $this->jsonRequest($this->api, 'POST', '/2/files/create_folder_v2', [
            'headers' => ['Authorization' => "Bearer {$this->access_token}", 'Content-Type' => 'application/json'],
            'body' => json_encode($payload)
        ]);
    }

    public function upload(string $localFile, string $path = '')
    {
        if (!is_readable($localFile)) return $this->setError("Local file not found or not readable: {$localFile}");
        if (!$this->ensureAccessTokenAvailable()) return false;

        $destDir = $path !== '' ? $this->normalizeDir($path) : $this->home_dir;
        $dropboxPath = rtrim($destDir, '/') . '/' . basename($localFile);

        // $stream = fopen($localFile, 'rb');
        // if ($stream === false) return $this->setError('Unable to open file for reading');
        $stream = file_get_contents($localFile);

        try {
            $res = $this->content->post('/2/files/upload', [
                'headers' => [
                    'Authorization' => "Bearer {$this->access_token}",
                    'Content-Type' => 'application/octet-stream',
                    'Dropbox-API-Arg' => json_encode([
                        'path' => $dropboxPath,
                        'mode' => $this->options['mode'] ?? 'overwrite',
                        'autorename' => $this->options['autorename'] ?? false,
                        'mute' => $this->options['mute'] ?? true
                    ])
                ],
                'body' => $stream
            ]);

            // fclose($stream);
            return json_decode((string)$res->getBody(), true);
        } catch (RequestException $e) {
            // if (is_resource($stream)) fclose($stream);
            $msg = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            return $this->setError($msg);
        }
    }

    public function delete(string $path)
    {
        if (!$this->ensureAccessTokenAvailable()) return false;
        return $this->jsonRequest($this->api, 'POST', '/2/files/delete_v2', [
            'headers' => ['Authorization' => "Bearer {$this->access_token}", 'Content-Type' => 'application/json'],
            'body' => json_encode(['path' => $path])
        ]);
    }

    public function createShareLink(string $path) {
        if (!$this->ensureAccessTokenAvailable()) return false;
        return $this->jsonRequest($this->api, 'POST', '/2/sharing/create_shared_link_with_settings', [
            'headers' => ['Authorization' => "Bearer {$this->access_token}", 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'path' => $path, 
                'settings' => [ 'requested_visibility' => 'public' ]
                ])
        ]);
	}

    public function getShareLink(string $path)
    {
        if (!$this->ensureAccessTokenAvailable()) return false;
        return $this->jsonRequest($this->api, 'POST', '/2/sharing/list_shared_links', [
            'headers' => ['Authorization' => "Bearer {$this->access_token}", 'Content-Type' => 'application/json'],
            'body' => json_encode(['path' => $path])
        ]);
    }

    public function deleteShareLink(string $url)
    {
        if (!$this->ensureAccessTokenAvailable()) return false;

        return $this->jsonRequest($this->api, 'POST', '/2/sharing/revoke_shared_link', [
            'headers' => [
                'Authorization' => "Bearer {$this->access_token}",
                'Content-Type'  => 'application/json'
            ],
            'body' => json_encode([
                'url' => $url
            ])
        ]);
    }

    public function updateShareLinkSettings(
        string $url, 
        string $visibility = 'public', 
        string $password = null, // untuk link menggunakan password
        ?string $expireDateUtc = null,      // YYYY-MM-DDTHH:MM:SSZ atau null untuk hapus expire
        bool $removeExpire = false          // true = hapus expiration
    ) {
        if (!$this->ensureAccessTokenAvailable()) return false;

        // List visibility yang valid
        $validVisibilities = [
            'public',
            'team_only',
            'password',
            'members',
            'disabled'
        ];

        // Validasi visibility
        if (!in_array($visibility, $validVisibilities, true)) {
            $this->last_error = "Invalid visibility: {$visibility}";
            return false;
        }

        // Settings dasar
        $settings = [
            'requested_visibility' => $visibility
        ];

        // Jika visibility = password
        if ($visibility === 'password') {
            if (empty($password)) {
                $this->last_error = "Password is required for visibility 'password'.";
                return false;
            }
            $settings['password'] = $password;
        }

        // Manajemen expiry
        if ($removeExpire === true) {
            // Hapus expire date → set null
            $settings['expires'] = null;
        } elseif (!empty($expireDateUtc)) {
            // Atur expire date (must ISO 8601 UTC)
            $settings['expires'] = $expireDateUtc;
        }

        return $this->jsonRequest($this->api, 'POST', '/2/sharing/modify_shared_link_settings', [
            'headers' => [
                'Authorization' => "Bearer {$this->access_token}",
                'Content-Type'  => 'application/json'
            ],
            'body' => json_encode([
                'url'      => $url,
                'settings' => $settings
            ])
        ]);
    }

    public function getFileFromURL(string $url)
    {
        if (!$this->ensureAccessTokenAvailable()) return false;
        return $this->jsonRequest($this->api, 'POST', '/2/sharing/get_shared_link_metadata', [
            'headers' => ['Authorization' => "Bearer {$this->access_token}", 'Content-Type' => 'application/json'],
            'body' => json_encode(['url' => $url])
        ]);
    }

    public function fileInfo(string $path)
    {
        if (!$this->ensureAccessTokenAvailable()) return false;
        return $this->jsonRequest($this->api, 'POST', '/2/files/get_metadata', [
            'headers' => ['Authorization' => "Bearer {$this->access_token}", 'Content-Type' => 'application/json'],
            'body' => json_encode(['path' => $path])
        ]);
    }

    public function listFolder(string $path = '')
    {
        if (!$this->ensureAccessTokenAvailable()) return false;
        $payload = ['path' => ($path !== '' ? $path : rtrim($this->home_dir, '/'))];
        return $this->jsonRequest($this->api, 'POST', '/2/files/list_folder', [
            'headers' => ['Authorization' => "Bearer {$this->access_token}", 'Content-Type' => 'application/json'],
            'body' => json_encode($payload)
        ]);
    }

    public function download(string $path)
    {
        if (!$this->ensureAccessTokenAvailable()) return false;
        try {
            $res = $this->content->post('/2/files/download', [
                'headers' => [
                    'Authorization' => "Bearer {$this->access_token}",
                    'Dropbox-API-Arg' => json_encode(['path' => $path])
                ]
            ]);
            return $res->getBody()->getContents();
        } catch (RequestException $e) {
            $msg = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();
            return $this->setError($msg);
        }
    }

    /* ------------------ Large / Chunked Upload ------------------ */
    public function uploadLargeFile(string $localFile, string $path = '', int $chunkSize = 8388608)
    {
        if (!is_readable($localFile)) return $this->setError("Local file not found: {$localFile}");
        if (!$this->ensureAccessTokenAvailable()) return false;

        $destDir = $path !== '' ? $this->normalizeDir($path) : $this->home_dir;
        $dropboxPath = rtrim($destDir, '/') . '/' . basename($localFile);

        $handle = fopen($localFile, 'rb');
        if ($handle === false) return $this->setError('Unable to open local file');

        $sessionId = null;
        $offset = 0;

        $first = fread($handle, $chunkSize);
        if ($first === false) { fclose($handle); return $this->setError('Unable to read first chunk'); }

        $resp = $this->requestWithRetries(function() use ($first) {
            return $this->content->post('/2/files/upload_session/start', [
                'headers' => [
                    'Authorization' => "Bearer {$this->access_token}",
                    'Content-Type' => 'application/octet-stream',
                    'Dropbox-API-Arg' => json_encode(['close' => false])
                ],
                'body' => $first
            ]);
        });

        if ($resp === false) { fclose($handle); return false; }

        $data = json_decode((string)$resp->getBody(), true);
        if (empty($data['session_id'])) { fclose($handle); return $this->setError('Failed to start upload session'); }

        $sessionId = $data['session_id'];
        $offset += strlen($first);

        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) break;

            $appendResp = $this->requestWithRetries(function() use ($chunk, $sessionId, $offset) {
                return $this->content->post('/2/files/upload_session/append_v2', [
                    'headers' => [
                        'Authorization' => "Bearer {$this->access_token}",
                        'Content-Type' => 'application/octet-stream',
                        'Dropbox-API-Arg' => json_encode([
                            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
                            'close' => false
                        ])
                    ],
                    'body' => $chunk
                ]);
            });

            if ($appendResp === false) { fclose($handle); return false; }
            $offset += strlen($chunk);
        }

        fclose($handle);

        $finishArg = [
            'cursor' => ['session_id' => $sessionId, 'offset' => $offset],
            'commit' => [
                'path' => $dropboxPath,
                'mode' => $this->options['mode'] ?? 'overwrite',
                'autorename' => $this->options['autorename'] ?? false,
                'mute' => $this->options['mute'] ?? true
            ]
        ];

        $finishResp = $this->requestWithRetries(function() use ($finishArg) {
            return $this->content->post('/2/files/upload_session/finish', [
                'headers' => [
                    'Authorization' => "Bearer {$this->access_token}",
                    'Content-Type' => 'application/octet-stream',
                    'Dropbox-API-Arg' => json_encode($finishArg)
                ],
                'body' => ''
            ]);
        });

        if ($finishResp === false) return false;
        return json_decode((string)$finishResp->getBody(), true);
    }

    protected function requestWithRetries(callable $fn)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt <= $this->chunkRetries) {
            try {
                $attempt++;
                $resp = $fn();
                return $resp;
            } catch (RequestException $e) {
                $lastException = $e;
                if ($attempt > $this->chunkRetries) break;
                sleep($this->chunkRetryDelay);
                continue;
            } catch (Exception $e) {
                $lastException = $e;
                break;
            }
        }

        $msg = $lastException ? ($lastException->getMessage() . (method_exists($lastException, 'hasResponse') && $lastException->hasResponse() ? ' | ' . (string)$lastException->getResponse()->getBody() : '')) : 'Unknown error';
        return $this->setError($msg);
    }
}