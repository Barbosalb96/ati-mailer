<?php

namespace Atima\ApiEmailLib;

use Atima\ApiEmailLib\Exception\Base64ProcessorException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Processa imagens inline em HTML de e-mail (formato data:image/...;base64,...).
 *
 * Para cada imagem encontrada:
 *   1. Valida e decodifica o base64 de forma controlada (via Base64FileProcessor).
 *   2. Verifica o MIME type real pelo conteúdo binário (finfo).
 *   3. Rejeita se não for imagem permitida ou > 40 MB.
 *   4. Salva via Storage::disk() com deduplicação por SHA-256.
 *   5. Substitui o data-URL no HTML pela URL pública gerada pelo Storage.
 *
 * Erros por imagem são logados (Log::warning) e a imagem é removida do HTML;
 * o restante do e-mail não é afetado.
 *
 * Registro no container (já feito pelo AtiEmailServiceProvider):
 *   app(InlineImageProcessor::class)
 *
 * Uso manual:
 *   $proc   = new InlineImageProcessor($fileProcessor, disk: 'public');
 *   $result = $proc->process($htmlBody);
 *   // $result['html']  → HTML sem base64 inline
 *   // $result['files'] → metadados dos arquivos salvos
 */
class InlineImageProcessor
{
    /**
     * Regex para imagens inline em HTML.
     *
     * Segurança contra ReDoS:
     *   - Quantificador possessivo `++` → sem backtracking na captura do base64.
     *   - Alternativas de tipo (`jpeg|png|gif|webp`) são strings curtas e fixas.
     *   - Flag `/S` ativa otimização interna do PCRE (pattern study).
     *   - Não há alternativas ambíguas nem aninhamento recursivo.
     */
    private const INLINE_IMG_PATTERN = '/data:image\/(jpeg|png|gif|webp);base64,([A-Za-z0-9+\/=]++)/S';

    /** MIMEs aceitos para imagens inline (subconjunto de Base64FileProcessor). */
    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Cache de requisição: data-URL completa → resultado processado (ou null se falhou).
     * Evita reprocessar a mesma imagem quando aparece repetida no HTML.
     *
     * @var array<string, array{hash:string,mime_type:string,size:int,path_relativo:string,url:string}|null>
     */
    private array $cache = [];

    /**
     * @param Base64FileProcessor $fileProcessor Instância compartilhada do processador de arquivos.
     * @param string              $disk          Disco Laravel (filesystems.disks) para gerar a URL pública.
     */
    public function __construct(
        private readonly Base64FileProcessor $fileProcessor,
        private readonly string $disk = 'public',
    ) {}

    // =========================================================================
    // API pública
    // =========================================================================

    /**
     * Varre o HTML, processa imagens inline e retorna o HTML modificado + metadados.
     *
     * @param  string $htmlBody HTML completo do corpo do e-mail.
     * @return array{
     *   html: string,
     *   files: array<int, array{hash: string, mime_type: string, size: int, path_relativo: string, url: string}>
     * }
     */
    public function process(string $htmlBody): array
    {
        $processedFiles = [];

        $modifiedHtml = preg_replace_callback(
            self::INLINE_IMG_PATTERN,
            function (array $m) use (&$processedFiles): string {
                return $this->handleMatch($m[0], $processedFiles);
            },
            $htmlBody,
        );

        // preg_replace_callback retorna null apenas em erro interno do PCRE.
        if ($modifiedHtml === null) {
            Log::error('[InlineImageProcessor] preg_replace_callback retornou null — HTML mantido sem alteração.', [
                'pcre_error' => preg_last_error_msg(),
            ]);
            $modifiedHtml = $htmlBody;
        }

        return [
            'html'  => $modifiedHtml,
            'files' => $processedFiles,
        ];
    }

    // =========================================================================
    // Processamento individual de cada match
    // =========================================================================

    /**
     * Tenta salvar a imagem e retorna a URL pública.
     * Em caso de erro, loga e retorna string vazia (remove o base64 do HTML).
     *
     * @param  array<int, array{hash:string,...}> $processedFiles Acumulador passado por referência.
     */
    private function handleMatch(string $fullDataUrl, array &$processedFiles): string
    {
        // Cache hit: mesma imagem encontrada mais de uma vez no HTML.
        if (array_key_exists($fullDataUrl, $this->cache)) {
            $cached = $this->cache[$fullDataUrl];
            if ($cached !== null) {
                $processedFiles[] = $cached;
                return $cached['url'];
            }
            return ''; // Esta imagem falhou em uma iteração anterior.
        }

        try {
            $result = $this->fileProcessor->processOne($fullDataUrl);

            // Camada extra de segurança: rejeita qualquer arquivo que não seja imagem,
            // mesmo que passe pela validação interna do Base64FileProcessor.
            // (ex: PDF disfarçado com cabeçalho data:image/png)
            if (! in_array($result['mime_type'], self::ALLOWED_IMAGE_MIMES, strict: true)) {
                throw new Base64ProcessorException(
                    Base64ProcessorException::REASON_MIME_NOT_ALLOWED,
                    "MIME real detectado não é uma imagem aceita: {$result['mime_type']}",
                );
            }

            $url           = Storage::disk($this->disk)->url($result['path_relativo']);
            $result['url'] = $url;

            $this->cache[$fullDataUrl] = $result;
            $processedFiles[]          = $result;

            return $url;

        } catch (\Throwable $e) {
            Log::warning('[InlineImageProcessor] Falha ao processar imagem inline.', [
                'reason'    => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $this->cache[$fullDataUrl] = null;
            return ''; // Remove o data-URL do HTML sem abortar o e-mail.
        }
    }
}
