<?php

namespace AutoTask;

use AutoTask\Exception\StorageException;

class AutoTask
{
    private array $config;
    private string $storagePath;
    private string $logFile;
    private string $stateFile;
    private string $lockDir;

    public function __construct(array $config, string $storagePath)
    {
        $storagePath = rtrim($storagePath, "/\\");

        if (!is_dir($storagePath)) {
            throw new StorageException('O diretório de storage não existe: ' . $storagePath);
        }

        if (!is_writable($storagePath)) {
            throw new StorageException('O diretório de storage não é gravável: ' . $storagePath);
        }

        $this->config      = $config;
        $this->storagePath = $storagePath;
        $this->logFile     = $storagePath . '/logs.jsonl';
        $this->stateFile   = $storagePath . '/state.json';
        $this->lockDir     = $storagePath . '/locks';

        if (!is_dir($this->lockDir)) {
            if (!@mkdir($this->lockDir, 0775, true) && !is_dir($this->lockDir)) {
                throw new StorageException('Não foi possível criar o diretório de locks: ' . $this->lockDir);
            }
        }

        if (!is_writable($this->lockDir)) {
            throw new StorageException('O diretório de locks não é gravável: ' . $this->lockDir);
        }
    }
}