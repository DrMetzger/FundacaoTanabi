<?php
/**
 * Gerador de PDF mínimo (sem dependências) para a ficha de inscrição.
 * A4, fontes Helvetica/Helvetica-Bold (WinAnsi), retângulos, linhas e uma imagem JPEG.
 */
declare(strict_types=1);

final class PdfSimples
{
    public const W = 595.28;
    public const H = 841.89;
    private array $paginas = [];
    private string $atual = '';
    private array $larg;
    private ?array $img = null;

    public function __construct()
    {
        $this->larg = require __DIR__ . '/fontes.php';
    }

    public static function enc(string $s): string
    {
        $s = strtr($s, ['✔' => 'X', '…' => '...']);
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $r === false ? $s : $r;
    }

    private static function esc(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    private static function cor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    public function novaPagina(): void
    {
        if ($this->atual !== '') $this->paginas[] = $this->atual;
        $this->atual = ' ';
    }

    /** Largura do texto (UTF-8) em pontos. */
    public function largura(string $txt, float $tam, bool $negrito = false): float
    {
        $t = self::enc($txt);
        $tab = $this->larg[$negrito ? 'F2' : 'F1'];
        $w = 0;
        for ($i = 0, $n = strlen($t); $i < $n; $i++) {
            $c = ord($t[$i]);
            $w += $tab[$c - 32] ?? 556;
        }
        return $w * $tam / 1000;
    }

    /** Quebra o texto em linhas que caibam na largura. */
    public function quebrar(string $txt, float $tam, float $max, bool $negrito = false): array
    {
        $linhas = [];
        foreach (preg_split('/\R/u', $txt) as $par) {
            $linha = '';
            foreach (preg_split('/\s+/u', trim($par)) as $pal) {
                $teste = $linha === '' ? $pal : "$linha $pal";
                if ($this->largura($teste, $tam, $negrito) <= $max || $linha === '') {
                    $linha = $teste;
                } else {
                    $linhas[] = $linha;
                    $linha = $pal;
                }
            }
            $linhas[] = $linha;
        }
        return $linhas;
    }

    /** y medido a partir do topo da página (linha de base). */
    public function texto(float $x, float $y, string $txt, float $tam = 10, bool $negrito = false, string $cor = '#172033'): void
    {
        $this->atual .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n",
            $negrito ? 'F2' : 'F1', $tam, self::cor($cor), $x, self::H - $y, self::esc(self::enc($txt)));
    }

    public function textoDireita(float $xDir, float $y, string $txt, float $tam = 10, bool $negrito = false, string $cor = '#172033'): void
    {
        $this->texto($xDir - $this->largura($txt, $tam, $negrito), $y, $txt, $tam, $negrito, $cor);
    }

    public function retangulo(float $x, float $y, float $w, float $h, string $preenche, ?string $borda = null): void
    {
        $op = $borda ? 'B' : 'f';
        $this->atual .= sprintf("%s rg %s%.2F %.2F %.2F %.2F re %s\n", self::cor($preenche),
            $borda ? self::cor($borda) . ' RG 0.6 w ' : '', $x, self::H - $y - $h, $w, $h, $op);
    }

    public function linha(float $x1, float $y1, float $x2, float $y2, string $cor = '#dde3ee', float $esp = 0.6): void
    {
        $this->atual .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::cor($cor), $esp, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    public function imagemJpeg(string $arquivo, float $x, float $y, float $w, float $h): void
    {
        if (!is_file($arquivo)) return;
        if ($this->img === null) {
            $info = getimagesize($arquivo);
            if (!$info || $info[2] !== IMAGETYPE_JPEG) return;
            $this->img = ['dados' => file_get_contents($arquivo), 'w' => $info[0], 'h' => $info[1]];
        }
        $this->atual .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n", $w, $h, $x, self::H - $y - $h);
    }

    public function saida(): string
    {
        if ($this->atual !== '') { $this->paginas[] = $this->atual; $this->atual = ''; }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $prox = 5;
        $xobj = '';
        if ($this->img) {
            $objs[$prox] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
                $this->img['w'], $this->img['h'], strlen($this->img['dados']), $this->img['dados']);
            $xobj = "/XObject << /Im1 $prox 0 R >>";
            $prox++;
        }
        $kids = [];
        foreach ($this->paginas as $conteudo) {
            $z = gzcompress($conteudo, 6);
            $objs[$prox] = sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", strlen($z), $z);
            $objs[$prox + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> %s >> >>',
                self::W, self::H, $prox, $xobj);
            $kids[] = ($prox + 1) . ' 0 R';
            $prox += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($objs as $n => $o) { $offs[$n] = strlen($pdf); $pdf .= "$n 0 obj\n$o\nendobj\n"; }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $pdf .= sprintf("%010d 00000 n \n", $offs[$i] ?? 0);
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}
