<?php

namespace Atima\ApiEmailLib;

use Atima\ApiEmailLib\Exception\Base64ProcessorException;
use Illuminate\Support\Facades\Storage;

/**
 * Processa campos base64 de um payload de requisição, convertendo-os em
 * arquivos físicos com validação rigorosa de MIME, tamanho e deduplicação.
 *
 * Uso (injeção via container):
 *   $processor = app(Base64FileProcessor::class);
 *   $files     = $processor->processPayload($request->all());
 *
 * Uso direto:
 *   $processor = new Base64FileProcessor(disk: 'public', folder: 'documents-email');
 *   $files     = $processor->processPayload($request->all());
 *
 * Cada item em $files contém: hash, mime_type, size, path_relativo.
 */
class Base64FileProcessor
{
    /** Tamanho de cada chunk de base64 lido (deve ser múltiplo de 4). */
    private const CHUNK_CHARS = 65536; // 64 KB de base64 ≈ 48 KB decodificado

    /** Limite máximo de tamanho após decodificação. */
    private const MAX_BYTES = 40 * 1024 * 1024; // 40 MB

    /**
     * MIME types permitidos e suas extensões canônicas.
     * A extensão é sempre derivada do MIME real — nunca do cliente.
     */
    private const ALLOWED_MIMES = [
        'application/pdf'                                                    => 'pdf',
        'text/csv'                                                           => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'image/jpeg'                                                         => 'jpg',
        'image/png'                                                          => 'png',
        'image/gif'                                                          => 'gif',
        'image/webp'                                                         => 'webp',
        'video/mp4'                                                          => 'mp4',
        'video/webm'                                                         => 'webm',
        'video/quicktime'                                                    => 'mov',
    ];

    /** Cache em memória de hashes já processados nesta requisição. */
    private array $cache = [];

    /**
     * @param  string $disk   Nome do disco Laravel (padrão: 'public').
     * @param  string $folder Pasta dentro do disco (padrão: 'documents-email').
     */
    public function __construct(
        private readonly string $disk   = 'public',
        private readonly string $folder = 'documents-email',
    ) {}

    // =========================================================================
    // API pública
    // =========================================================================

    /**
     * Varre recursivamente um payload e processa todos os base64 encontrados.
     *
     * Strings que não representam arquivos válidos são silenciosamente ignoradas.
     *
     * @param  array $payload Corpo da requisição ($request->all()).
     * @return array<int, array{hash: string, mime_type: string, size: int, path_relativo: string}>
     */
    public function processPayload(array $payload): array
    {
        $results = [];
        $this->scan($payload, $results);
        return $results;
    }

    /**
     * Processa uma única string base64 (data-URL ou raw).
     *
     * @return array{hash: string, mime_type: string, size: int, path_relativo: string}
     * @throws Base64ProcessorException Em caso de base64 inválido, MIME proibido,
     *                                  arquivo muito grande ou erro de escrita.
     */
    public function processOne(string $input): array
    {
        [$raw] = $this->stripDataUrl($input);

        $this->assertValidBase64Chars($raw);

        $tmpPath = $this->decodeToTempFile($raw);

        try {
            $mime = $this->detectRealMime($tmpPath);

            if (! array_key_exists($mime, self::ALLOWED_MIMES)) {
                throw new Base64ProcessorException(
                    Base64ProcessorException::REASON_MIME_NOT_ALLOWED,
                    "MIME type não permitido: \"{$mime}\". Tipos aceitos: "
                        . implode(', ', array_keys(self::ALLOWED_MIMES)),
                );
            }

            $extension    = self::ALLOWED_MIMES[$mime];
            $hash         = hash_file('sha256', $tmpPath);
            $filename     = $hash . '.' . $extension;
            $storagePath  = $this->folder . '/' . $filename;

            // Deduplicação: retorna o registro existente sem reescrever.
            if (isset($this->cache[$hash])) {
                return $this->cache[$hash];
            }

            if (Storage::disk($this->disk)->exists($storagePath)) {
                $result = $this->buildResult(
                    $hash,
                    $mime,
                    Storage::disk($this->disk)->size($storagePath),
                    $storagePath,
                );
                return $this->cache[$hash] = $result;
            }

            // Salva no disco Laravel via stream para não duplicar conteúdo em memória.
            $stream = fopen($tmpPath, 'rb');
            if ($stream === false) {
                throw new Base64ProcessorException(
                    Base64ProcessorException::REASON_WRITE_ERROR,
                    "Não foi possível abrir o arquivo temporário para leitura: {$tmpPath}",
                );
            }

            $stored = Storage::disk($this->disk)->put($storagePath, $stream, 'public');
            fclose($stream);

            if (! $stored) {
                throw new Base64ProcessorException(
                    Base64ProcessorException::REASON_WRITE_ERROR,
                    "Falha ao salvar o arquivo no disco \"{$this->disk}\": {$storagePath}",
                );
            }

            $result = $this->buildResult(
                $hash,
                $mime,
                Storage::disk($this->disk)->size($storagePath),
                $storagePath,
            );
            return $this->cache[$hash] = $result;

        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    // =========================================================================
    // Varredura recursiva do payload
    // =========================================================================

    private function scan(array $data, array &$results): void
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                $this->scan($value, $results);
            } elseif (is_string($value) && $this->looksLikeBase64($value)) {
                try {
                    $results[] = $this->processOne($value);
                } catch (Base64ProcessorException) {
                    // Campo não é um arquivo base64 válido — ignora sem quebrar o fluxo.
                }
            }
        }
    }

    // =========================================================================
    // Parsing de data-URL
    // =========================================================================

    /**
     * Separa o raw base64 de um eventual prefixo data-URL.
     * Retorna [rawBase64, mimeFromHeader|null].
     *
     * @return array{string, string|null}
     */
    private function stripDataUrl(string $input): array
    {
        if (str_starts_with($input, 'data:')) {
            if (preg_match('/^data:([a-zA-Z0-9!#$&\-^_]+\/[a-zA-Z0-9!#$&\-^_+.]+);base64,(.+)$/s', $input, $m)) {
                return [$m[2], $m[1]];
            }
        }

        return [$input, null];
    }

    // =========================================================================
    // Validação de base64
    // =========================================================================

    /**
     * Heurística rápida para evitar processar campos que claramente não são base64.
     */
    private function looksLikeBase64(string $value): bool
    {
        if (strlen($value) < 64) {
            return false;
        }

        [$raw] = $this->stripDataUrl($value);
        $clean = preg_replace('/\s+/', '', $raw);

        return strlen($clean) >= 64
            && strlen($clean) % 4 === 0
            && preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $clean) === 1;
    }

    /**
     * @throws Base64ProcessorException Se a string contiver caracteres inválidos para base64.
     */
    private function assertValidBase64Chars(string $raw): void
    {
        $clean = preg_replace('/\s+/', '', $raw);

        if (
            strlen($clean) === 0
            || strlen($clean) % 4 !== 0
            || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $clean) !== 1
        ) {
            throw new Base64ProcessorException(
                Base64ProcessorException::REASON_INVALID_BASE64,
                'A string fornecida não é um base64 válido (charset ou padding incorreto).',
            );
        }
    }

    // =========================================================================
    // Decodificação em chunks para arquivo temporário
    // =========================================================================

    /**
     * Decodifica o base64 em chunks de CHUNK_CHARS caracteres e grava em um
     * arquivo temporário, respeitando o limite de tamanho.
     *
     * @throws Base64ProcessorException
     */
    private function decodeToTempFile(string $raw): string
    {
        $clean  = preg_replace('/\s+/', '', $raw);
        $length = strlen($clean);

        $tmpPath = tempnam(sys_get_temp_dir(), 'ati_b64_');
        if ($tmpPath === false) {
            throw new Base64ProcessorException(
                Base64ProcessorException::REASON_WRITE_ERROR,
                'Não foi possível criar arquivo temporário no sistema.',
            );
        }

        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            throw new Base64ProcessorException(
                Base64ProcessorException::REASON_WRITE_ERROR,
                "Não foi possível abrir o arquivo temporário para escrita: {$tmpPath}",
            );
        }

        $totalDecoded = 0;
        $offset       = 0;

        try {
            while ($offset < $length) {
                $chunk   = substr($clean, $offset, self::CHUNK_CHARS);
                $offset += self::CHUNK_CHARS;

                $decoded = base64_decode($chunk, strict: true);

                if ($decoded === false) {
                    throw new Base64ProcessorException(
                        Base64ProcessorException::REASON_INVALID_BASE64,
                        'Falha ao decodificar chunk de base64 — dados corrompidos.',
                    );
                }

                $totalDecoded += strlen($decoded);

                if ($totalDecoded > self::MAX_BYTES) {
                    throw new Base64ProcessorException(
                        Base64ProcessorException::REASON_TOO_LARGE,
                        sprintf(
                            'O arquivo excede o limite máximo de %d MB após decodificação.',
                            self::MAX_BYTES / 1024 / 1024,
                        ),
                    );
                }

                fwrite($fh, $decoded);
            }
        } finally {
            fclose($fh);
        }

        return $tmpPath;
    }

    // =========================================================================
    // Detecção de MIME real
    // =========================================================================

    /**
     * Detecta o MIME type real inspecionando os magic bytes do arquivo.
     * Nunca confia em extensão ou cabeçalho informado pelo cliente.
     *
     * @throws Base64ProcessorException Se o MIME não puder ser determinado.
     */
    private function detectRealMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path);

        if ($mime === false) {
            throw new Base64ProcessorException(
                Base64ProcessorException::REASON_MIME_NOT_ALLOWED,
                'Não foi possível determinar o MIME type real do arquivo.',
            );
        }

        return $mime;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array{hash: string, mime_type: string, size: int, path_relativo: string} */
    private function buildResult(string $hash, string $mime, int $size, string $storagePath): array
    {
        return [
            'hash'          => $hash,
            'mime_type'     => $mime,
            'size'          => $size,
            'path_relativo' => $storagePath,
        ];
    }
}
