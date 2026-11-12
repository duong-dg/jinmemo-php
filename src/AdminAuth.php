<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message) { parent::__construct($message); }
}

final class AdminAuth
{
    public function __construct(private PDO $db, private array $config) {}
    private function query(string $sql, array $args = []): PDOStatement
    {
        $statement = $this->db->prepare($sql); $statement->execute($args); return $statement;
    }
    public function policy(bool $lock = false): array
    {
        $raw = $this->query("SELECT data FROM jingo_admin_security WHERE id='policy'" . ($lock ? ' FOR UPDATE' : ''))->fetchColumn();
        if ($raw === false) throw new HttpError(503, 'Chưa cài chính sách bảo mật.');
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
    public function mutation(): void
    {
        if (($_SERVER['HTTP_X_JINGO_ADMIN'] ?? '') !== '1') throw new HttpError(403, 'Thiếu xác minh yêu cầu.');
        $url = $this->config['PUBLIC_BASE_URL'] ?? '';
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http','https'], true)) throw new HttpError(503, 'Chưa cấu hình PUBLIC_BASE_URL.');
        if (($this->config['APP_ENV'] ?? 'production') === 'production' && $parts['scheme'] !== 'https') throw new HttpError(503, 'Hosting cần HTTPS.');
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== $origin) throw new HttpError(403, 'Nguồn yêu cầu không hợp lệ.');
    }
    public function entry(array $policy): void
    {
        if (!empty($policy['privateEntryEnabled']) && (empty($policy['entrySlug']) || !hash_equals($policy['entrySlug'], $_SERVER['HTTP_X_JINGO_ADMIN_ENTRY'] ?? ''))) throw new HttpError(404, 'Không tìm thấy.');
    }
    private function throttle(string $key, int $max): void
    {
        $id = hash('sha256', $key);
        $this->db->beginTransaction();
        try {
            $this->query('INSERT INTO jingo_admin_login_limits(id,until_at) VALUES (?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE id=id', [$id]);
            $row = $this->query('SELECT attempts,until_at FROM jingo_admin_login_limits WHERE id=? FOR UPDATE', [$id])->fetch();
            $expired = strtotime($row['until_at'].' UTC') <= time();
            $count = $expired ? 1 : (int)$row['attempts'] + 1;
            $this->query('UPDATE jingo_admin_login_limits SET attempts=?,until_at=? WHERE id=?', [$count, $expired ? gmdate('Y-m-d H:i:s', time()+900) : $row['until_at'], $id]);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        if ($count > $max) throw new HttpError(429, 'Thử lại sau 15 phút.');
    }
    private function log(string $event, string $email, ?string $id = null): void
    {
        $data = ['ip'=>substr($_SERVER['REMOTE_ADDR'] ?? '',0,80), 'userAgent'=>substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,500)];
        $this->query('INSERT INTO jingo_admin_login_log(id,admin_id,email,event,data) VALUES (?,?,?,?,?)', [bin2hex(random_bytes(16)), $id, $email, $event, json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)]);
    }
    private function cookie(string $token, int $expires): void
    {
        setcookie('jingo_admin', $token, ['expires'=>$expires,'path'=>'/','secure'=>str_starts_with($this->config['PUBLIC_BASE_URL'] ?? '', 'https://'),'httponly'=>true,'samesite'=>'Strict']);
    }
    public function login(array $input): array
    {
        $this->mutation(); $this->entry($this->policy());
        $email = $input['email'] ?? null; $password = $input['password'] ?? null;
        if (!is_string($email) || strlen($email)>254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password)<1 || strlen($password)>1024 || array_diff(array_keys($input), ['email','password','device'])) throw new HttpError(400, 'Email hoặc mật khẩu không hợp lệ.');
        $email = strtolower($email);
        try { $this->throttle('peer:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'),100); $this->throttle('account:'.$email,5); }
        catch (HttpError $e) { $this->log('THROTTLED',$email); throw $e; }
        $admin = $this->query('SELECT id,email,password_hash FROM jingo_admins WHERE email=?',[$email])->fetch();
        // Native PHP hashes only. Legacy Node scrypt hashes require a password reset.
        $hash = $admin ? $admin['password_hash'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $verified = password_verify($password,$hash);
        if (!$verified || !$admin || (str_starts_with($hash,'$2y$') && strlen($password)>72)) { $this->log('PASSWORD_FAILED',$email); throw new HttpError(401,'Email hoặc mật khẩu không đúng.'); }
        $this->db->beginTransaction();
        try {
            $policy = $this->policy(true); $this->entry($policy);
            if (!empty($policy['otpEnabled'])) throw new HttpError(503,'OTP chưa được chuyển sang PHP; chưa tạo phiên đăng nhập.');
            $token = bin2hex(random_bytes(32));
            $this->query('DELETE FROM jingo_sessions WHERE expires_at<=UTC_TIMESTAMP()');
            $this->query('INSERT INTO jingo_sessions(token_hash,admin_id,expires_at) VALUES (?,?,?)',[hash('sha256',$token),$admin['id'],gmdate('Y-m-d H:i:s',time()+28800)]);
            $this->query('DELETE FROM jingo_admin_login_limits WHERE id=?',[hash('sha256','account:'.$email)]);
            $this->log('SUCCESS',$email,$admin['id']); $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        $this->cookie($token,time()+28800); return ['email'=>$admin['email']];
    }
    public function me(): array
    {
        $token = $_COOKIE['jingo_admin'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new HttpError(401,'Vui lòng đăng nhập.');
        $admin = $this->query('SELECT a.id,a.email FROM jingo_sessions s JOIN jingo_admins a ON a.id=s.admin_id WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP()',[hash('sha256',$token)])->fetch();
        if (!$admin) throw new HttpError(401,'Phiên đăng nhập đã hết hạn.');
        return $admin;
    }
    public function logout(): array
    {
        $this->mutation(); $token = $_COOKIE['jingo_admin'] ?? '';
        $this->db->beginTransaction();
        try {
            try { $admin = $this->me(); $this->log('LOGOUT',$admin['email'],$admin['id']); } catch (HttpError $e) { if ($e->status !== 401) throw $e; }
            if (is_string($token)) $this->query('DELETE FROM jingo_sessions WHERE token_hash=?',[hash('sha256',$token)]);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        $this->cookie('',time()-3600); return ['ok'=>true];
    }
}
