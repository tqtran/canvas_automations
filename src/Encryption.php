<?php
declare(strict_types=1);

namespace CanvasAutomations;

/**
 * Encryption Utility
 * 
 * Provides AES-256-GCM encryption/decryption for sensitive data.
 * Uses authenticated encryption with Galois/Counter Mode (GCM) for integrity.
 * 
 * @package CanvasAutomations
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16; // 128-bit authentication tag
    
    private string $key;
    
    /**
     * Initialize encryption with key
     * 
     * @param string $key 64-character hex string (32 bytes)
     * @throws \InvalidArgumentException if key is invalid
     */
    public function __construct(string $key)
    {
        // Validate key format (must be 64 hex characters = 32 bytes)
        if (!\preg_match('/^[0-9a-f]{64}$/i', $key)) {
            throw new \InvalidArgumentException(
                'Encryption key must be 64 hexadecimal characters (32 bytes)'
            );
        }
        
        // Convert hex key to binary
        $this->key = \hex2bin($key);
        
        if ($this->key === false || \strlen($this->key) !== 32) {
            throw new \InvalidArgumentException('Invalid encryption key format');
        }
    }
    
    /**
     * Encrypt data
     * 
     * @param string $plaintext Data to encrypt
     * @return string Base64-encoded ciphertext with IV and tag
     * @throws \RuntimeException if encryption fails
     */
    public function encrypt(string $plaintext): string
    {
        // Generate random IV (12 bytes recommended for GCM)
        $iv = \openssl_random_pseudo_bytes(12);
        
        if ($iv === false) {
            throw new \RuntimeException('Failed to generate IV');
        }
        
        // Encrypt with GCM mode
        $tag = '';
        $ciphertext = \openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );
        
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        
        // Combine IV + ciphertext + tag and encode
        $encrypted = $iv . $ciphertext . $tag;
        
        return \base64_encode($encrypted);
    }
    
    /**
     * Decrypt data
     * 
     * @param string $encrypted Base64-encoded ciphertext with IV and tag
     * @return string Decrypted plaintext
     * @throws \RuntimeException if decryption fails
     */
    public function decrypt(string $encrypted): string
    {
        // Decode from base64
        $data = \base64_decode($encrypted, true);
        
        if ($data === false) {
            throw new \RuntimeException('Invalid encrypted data format');
        }
        
        // Extract IV (12 bytes), ciphertext, and tag (16 bytes)
        $ivLength = 12;
        $tagLength = self::TAG_LENGTH;
        
        if (\strlen($data) < ($ivLength + $tagLength)) {
            throw new \RuntimeException('Encrypted data too short');
        }
        
        $iv = \substr($data, 0, $ivLength);
        $tag = \substr($data, -$tagLength);
        $ciphertext = \substr($data, $ivLength, -$tagLength);
        
        // Decrypt with GCM mode
        $plaintext = \openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            \OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed or authentication tag invalid');
        }
        
        return $plaintext;
    }
    
    /**
     * Check if a value is already encrypted
     * 
     * @param string $value Value to check
     * @return bool True if appears to be encrypted
     */
    public function isEncrypted(string $value): bool
    {
        // Encrypted values are base64 and have minimum length
        if (!\preg_match('/^[A-Za-z0-9+\/]+=*$/', $value)) {
            return false;
        }
        
        $decoded = \base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        
        // Must have at least IV (12) + tag (16) = 28 bytes
        return \strlen($decoded) >= 28;
    }
    
    /**
     * Generate a new random encryption key
     * 
     * @return string 64-character hex string
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
