<?php

namespace Novosga\ReportsBundle\Controller;

use Exception;
use Novosga\Http\Envelope;
use Novosga\Service\AtendimentoService;
use Novosga\Service\UnidadeService;
use Novosga\Service\UsuarioService;
use Novosga\Util\DateUtil;
use Novosga\Util\Strings;
use Novosga\ReportsBundle\Helper\Grafico;
use Novosga\ReportsBundle\Helper\Relatorio;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

/**
 *
 * @author Rogerio Lino <rogeriolino@gmail.com>
 */
class DefaultController extends Controller
{
    const MAX_RESULTS = 1000;

    private $graficos;
    private $relatorios;

    public function __construct()
    {
        $this->graficos = [
            1 => new Grafico(_('Atendimentos por status'), 'pie', 'unidade,date-range'),
            2 => new Grafico(_('Atendimentos por serviço'), 'pie', 'unidade,date-range'),
            3 => new Grafico(_('Tempo médio do atendimento'), 'bar', 'unidade,date-range'),
        ];
        $this->relatorios = [
            1 => new Relatorio(_('Serviços Disponíveis - Global'), 'servicos_disponiveis_global'),
            2 => new Relatorio(_('Serviços Disponíveis - Unidade'), 'servicos_disponiveis_unidades', 'unidade'),
            3 => new Relatorio(_('Serviços codificados'), 'servicos_codificados', 'unidade,date-range'),
            4 => new Relatorio(_('Atendimentos concluídos'), 'atendimentos_concluidos', 'unidade,date-range'),
            5 => new Relatorio(_('Atendimentos em todos os status'), 'atendimentos_status', 'unidade,date-range'),
            6 => new Relatorio(_('Tempos médios por Atendente'), 'tempo_medio_atendentes', 'date-range'),
            7 => new Relatorio(_('Lotações'), 'lotacoes', 'unidade'),
            8 => new Relatorio(_('Cargos'), 'cargos'),
        ];
    }

    /**
     * 
     * @param Request $request
     * 
     * @Route("/", name="novosga_reports_index")
     */
    public function indexAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $query = $em->createQuery("SELECT e FROM Novosga\Entity\Unidade e WHERE e.status = 1 ORDER BY e.nome");
        $unidades = $query->getResult();
        
        $arr = [];
        foreach ($unidades as $u) {
            $arr[$u->getId()] = $u->getNome();
        }
        
        return $this->render('NovosgaReportsBundle:default:index.html.twig', [
            'unidades' => $unidades,
            'relatorios' => $this->relatorios,
            'graficos' => $this->graficos,
            'statusAtendimento' => AtendimentoService::situacoes(),
            'unidadesJson' => json_encode($arr),
            'now' => DateUtil::now(_('d/m/Y'))
        ]);
    }

    /**
     * Retorna os gráficos do dia a partir da unidade informada.
     */
    public function today(Context $context)
    {
        $envelope = new Envelope();
        try {
            $ini = DateUtil::now('Y-m-d');
            $fim = DateUtil::nowSQL(); // full datetime
            $unidade = (int) $request->get('unidade');
            $status = $this->total_atendimentos_status($ini, $fim, $unidade);
            $data = [
                'legendas' => AtendimentoService::situacoes(),
                'status' => $status[$unidade]
            ];
            $servicos = $this->total_atendimentos_servico($ini, $fim, $unidade);
            $data['servicos'] = $servicos[$unidade];
            $envelope->setData($data);
        } catch (Exception $e) {
            $envelope
                    ->setSuccess(false)
                    ->setMessage($e->getMessage());
        }

        return $this->json($envelope);
    }

    public function grafico(Context $context)
    {
        $envelope = new Envelope();
        try {
            $id = (int) $request->get('grafico');
            $dataInicial = $request->get('inicial');
            $dataFinal = $request->get('final').' 23:59:59';
            $unidade = (int) $request->get('unidade');
            $unidade = ($unidade > 0) ? $unidade : 0;
            if (!isset($this->graficos[$id])) {
                throw new Exception(_('Gráfico inválido'));
            }
            $grafico = $this->graficos[$id];
            switch ($id) {
            case 1:
                $grafico->setLegendas(AtendimentoService::situacoes());
                $grafico->setDados($this->total_atendimentos_status($dataInicial, $dataFinal, $unidade));
                break;
            case 2:
                $grafico->setDados($this->total_atendimentos_servico($dataInicial, $dataFinal, $unidade));
                break;
            case 3:
                $grafico->setDados($this->tempo_medio_atendimentos($dataInicial, $dataFinal, $unidade));
                break;
            }
            $data = $grafico->jsonSerialize();
            $envelope->setData($data);
        } catch (\Exception $e) {
            $envelope
                    ->setSuccess(false)
                    ->setMessage($e->getMessage());
        }

        return $this->json($envelope);
    }

    public function relatorio(Context $context)
    {
        $id = (int) $request->get('relatorio');
        $dataInicial = $request->get('inicial');
        $dataFinal = $request->get('final');
        $unidade = (int) $request->get('unidade');
        $unidade = ($unidade > 0) ? $unidade : 0;
        if (isset($this->relatorios[$id])) {
            $relatorio = $this->relatorios[$id];
            $this->app()->view()->set('dataInicial', DateUtil::format($dataInicial, _('d/m/Y')));
            $this->app()->view()->set('dataFinal', DateUtil::format($dataFinal, _('d/m/Y')));
            $dataFinal = $dataFinal.' 23:59:59';
            switch ($id) {
            case 1:
                $relatorio->setDados($this->servicos_disponiveis_global());
                break;
            case 2:
                $relatorio->setDados($this->servicos_disponiveis_unidade($unidade));
                break;
            case 3:
                $relatorio->setDados($this->servicos_codificados($dataInicial, $dataFinal, $unidade));
                break;
            case 4:
                $relatorio->setDados($this->atendimentos_concluidos($dataInicial, $dataFinal, $unidade));
                break;
            case 5:
                $relatorio->setDados($this->atendimentos_status($dataInicial, $dataFinal, $unidade));
                break;
            case 6:
                $relatorio->setDados($this->tempo_medio_atendentes($dataInicial, $dataFinal));
                break;
            case 7:
                $servico = $request->get('servico');
                $relatorio->setDados($this->lotacoes($unidade, $servico));
                break;
            case 8:
                $relatorio->setDados($this->cargos());
                break;
            }
            $this->app()->view()->set('relatorio', $relatorio);
        }
        $this->app()->view()->set('page', "relatorios/{$relatorio->getArquivo()}.html.twig");
    }

    private function unidades()
    {
        $query = $this->em()->createQuery("SELECT e FROM Novosga\Entity\Unidade e WHERE e.status = 1 ORDER BY e.nome");

        return $query->getResult();
    }

    private function unidadesArray($default = 0)
    {
        if ($default == 0) {
            return $this->unidades();
        } else {
            $unidade = $this->em()->find('Novosga\Entity\Unidade', $default);
            if (!$unidade) {
                throw new \Exception('Invalid parameter');
            }

            return [$unidade];
        }
    }

    private function total_atendimentos_status($dataInicial, $dataFinal, $unidadeId = 0)
    {
        $unidades = $this->unidadesArray($unidadeId);
        $dados = [];
        $status = AtendimentoService::situacoes();
        $query = $this->em()->createQuery("
            SELECT
                COUNT(e) as total
            FROM
                Novosga\Entity\ViewAtendimento e
            WHERE
                e.dataChegada >= :inicio AND
                e.dataChegada <= :fim AND
                e.unidade = :unidade AND
                e.status = :status
        ");
        $query->setParameter('inicio', $dataInicial);
        $query->setParameter('fim', $dataFinal);
        foreach ($unidades as $unidade) {
            $dados[$unidade->getId()] = [];
            // pegando todos os status
            foreach ($status as $k => $v) {
                $query->setParameter('unidade', $unidade->getId());
                $query->setParameter('status', $k);
                $rs = $query->getSingleResult();
                $dados[$unidade->getId()][$k] = (int) $rs['total'];
            }
        }

        return $dados;
    }

    private function total_atendimentos_servico($dataInicial, $dataFinal, $unidadeId = 0)
    {
        $unidades = $this->unidadesArray($unidadeId);
        $dados = [];
        $query = $this->em()->createQuery("
            SELECT
                s.nome as servico,
                COUNT(a) as total
            FROM
                Novosga\Entity\ViewAtendimento a
                JOIN a.unidade u
                JOIN a.servico s
            WHERE
                a.status = :status AND
                a.dataChegada >= :inicio AND
                a.dataChegada <= :fim AND
                a.unidade = :unidade
            GROUP BY
                s
        ");
        $query->setParameter('status', AtendimentoService::ATENDIMENTO_ENCERRADO_CODIFICADO);
        $query->setParameter('inicio', $dataInicial);
        $query->setParameter('fim', $dataFinal);
        foreach ($unidades as $unidade) {
            $query->setParameter('unidade', $unidade->getId());
            $rs = $query->getResult();
            $dados[$unidade->getId()] = [];
            foreach ($rs as $r) {
                $dados[$unidade->getId()][$r['servico']] = $r['total'];
            }
        }

        return $dados;
    }

    private function tempo_medio_atendimentos($dataInicial, $dataFinal, $unidadeId = 0)
    {
        $unidades = $this->unidadesArray($unidadeId);
        $dados = [];
        $tempos = [
            'espera'       => _('Tempo de Espera'),
            'deslocamento' => _('Tempo de Deslocamento'),
            'atendimento'  => _('Tempo de Atendimento'),
            'total'        => _('Tempo Total'),
        ];
        $dql = "
            SELECT
                AVG(a.dataChamada - a.dataChegada) as espera,
                AVG(a.dataInicio - a.dataChamada) as deslocamento,
                AVG(a.dataFim - a.dataInicio) as atendimento,
                AVG(a.dataFim - a.dataChegada) as total
            FROM
                Novosga\Entity\ViewAtendimento a
                JOIN a.unidade u
            WHERE
                a.dataChegada >= :inicio AND
                a.dataChegada <= :fim AND
                a.unidade = :unidade
        ";
        $query = $this->em()->createQuery($dql);
        $query->setParameter('inicio', $dataInicial);
        $query->setParameter('fim', $dataFinal);
        foreach ($unidades as $unidade) {
            $query->setParameter('unidade', $unidade->getId());
            $rs = $query->getResult();
            $dados[$unidade->getId()] = [];
            foreach ($rs as $r) {
                try {
                    // se der erro tentando converter a data do banco para segundos, assume que ja esta em segundos
                    // Isso é necessário para manter a compatibilidade entre os bancos
                    foreach ($tempos as $k => $v) {
                        $dados[$unidade->getId()][$v] = DateUtil::timeToSec($r[$k]);
                    }
                } catch (\Exception $e) {
                    foreach ($tempos as $k => $v) {
                        $dados[$unidade->getId()][$v] = (int) $r[$k];
                    }
                }
            }
        }

        return $dados;
    }

    private function servicos_disponiveis_global()
    {
        $query = $this->em()->createQuery("
            SELECT
                e
            FROM
                Novosga\Entity\Servico e
                LEFT JOIN e.subServicos sub
            WHERE
                e.mestre IS NULL
            ORDER BY
                e.nome
        ");

        return $query->getResult();
    }

    /**
     * Retorna todos os servicos disponiveis para cada unidade.
     *
     * @return array
     */
    private function servicos_disponiveis_unidade($unidadeId = 0)
    {
        $unidades = $this->unidadesArray($unidadeId);
        $dados = [];
        $query = $this->em()->createQuery("
            SELECT
                e
            FROM
                Novosga\Entity\ServicoUnidade e
                JOIN e.servico s
                LEFT JOIN s.subServicos sub
            WHERE
                s.mestre IS NULL AND
                e.status = 1 AND
                e.unidade = :unidade
            ORDER BY
                s.nome
        ");
        foreach ($unidades as $unidade) {
            $query->setParameter('unidade', $unidade);
            $dados[$unidade->getId()] = [
                'unidade'  => $unidade->getNome(),
                'servicos' => $query->getResult(),
            ];
        }

        return $dados;
    }

    private function servicos_codificados($dataInicial, $dataFinal, $unidadeId = 0)
    {
        $unidades = $this->unidadesArray($unidadeId);
        $query = $this->em()->createQuery("
            SELECT
                COUNT(s.id) as total,
                s.nome
            FROM
                Novosga\Entity\ViewAtendimentoCodificado c
                JOIN c.servico s
                JOIN c.atendimento e
            WHERE
                e.unidade = :unidade AND
                e.dataChegada >= :dataInicial AND
                e.dataChegada <= :dataFinal
            GROUP BY
                s
            ORDER BY
                s.nome
        ");
        $query->setParameter('dataInicial', $dataInicial);
        $query->setParameter('dataFinal', $dataFinal);
        $query->setMaxResults(self::MAX_RESULTS);
        $dados = [];
        foreach ($unidades as $unidade) {
            $query->setParameter('unidade', $unidade);
            $dados[$unidade->getId()] = [
                'unidade'  => $unidade->getNome(),
                'servicos' => $query->getResult(),
            ];
        }

        return $dados;
    }

    private function atendimentos_concluidos($dataInicial, $dataFinal, $unidadeId = 0)
    {
        $unidades = $this->unidadesArray($unidadeId);
        $dados = [];
        $query = $this->em()->createQuery("
            SELECT
                e
            FROM
                Novosga\Entity\ViewAtendimento e
            WHERE
                e.unidade = :unidade AND
                e.status = :status AND
                e.dataChegada >= :dataInicial AND
                e.dataChegada <= :dataFinal
            ORDER BY
                e.dataChegada
        ");
        $query->setParameter('status', AtendimentoService::ATENDIMENTO_ENCERRADO_CODIFICADO);
        $query->setParameter('dataInicial', $dataInicial);
        $query->setParameter('dataFinal', $dataFinal);
        $query->setMaxResults(self::MAX_RESULTS);
        foreach ($unidades as $unidade) {
            $query->setParameter('unidade', $unidade);
            $dados[$unidade->getId()] = [
                'unidade'      => $unidade->getNome(),
                'atendimentos' => $query->getResult(),
            ];
        }

        return $dados;
    }

    private function atendimentos_status($dataInicial, $dataFinal, $unidadeId = 0)
    {
        $unidades = $this->unidadesArray($unidadeId);
        $dados = [];
        $query = $this->em()->createQuery("
            SELECT
                e
            FROM
                Novosga\Entity\ViewAtendimento e
            WHERE
                e.unidade = :unidade AND
                e.dataChegada >= :dataInicial AND
                e.dataChegada <= :dataFinal
            ORDER BY
                e.dataChegada
        ");
        $query->setParameter('dataInicial', $dataInicial);
        $query->setParameter('dataFinal', $dataFinal);
        $query->setMaxResults(self::MAX_RESULTS);
        foreach ($unidades as $unidade) {
            $query->setParameter('unidade', $unidade);
            $dados[$unidade->getId()] = [
                'unidade'      => $unidade->getNome(),
                'atendimentos' => $query->getResult(),
            ];
        }

        return $dados;
    }

    private function tempo_medio_atendentes($dataInicial, $dataFinal)
    {
        $dados = [];
        $query = $this->em()->createQuery("
            SELECT
                CONCAT(u.nome, CONCAT(' ', u.sobrenome)) as atendente,
                COUNT(a) as total,
                AVG(a.dataChamada - a.dataChegada) as espera,
                AVG(a.dataInicio - a.dataChamada) as deslocamento,
                AVG(a.dataFim - a.dataInicio) as atendimento,
                AVG(a.dataFim - a.dataChegada) as tempoTotal
            FROM
                Novosga\Entity\ViewAtendimento a
                JOIN a.usuario u
            WHERE
                a.dataChegada >= :dataInicial AND
                a.dataChegada <= :dataFinal AND
                a.dataFim IS NOT NULL
            GROUP BY
                u
            ORDER BY
                u.nome
        ");
        $query->setParameter('dataInicial', $dataInicial);
        $query->setParameter('dataFinal', $dataFinal);
        $query->setMaxResults(self::MAX_RESULTS);
        $rs = $query->getResult();
        foreach ($rs as $r) {
            $d = [
                'atendente' => $r['atendente'],
                'total'     => $r['total'],
            ];
            try {
                // se der erro tentando converter a data do banco para segundos, assume que ja esta em segundos
                // Isso é necessário para manter a compatibilidade entre os bancos
                $d['espera'] = DateUtil::timeToSec($r['espera']);
                $d['deslocamento'] = DateUtil::timeToSec($r['deslocamento']);
                $d['atendimento'] = DateUtil::timeToSec($r['atendimento']);
                $d['tempoTotal'] = DateUtil::timeToSec($r['tempoTotal']);
            } catch (\Exception $e) {
                $d['espera'] = $r['espera'];
                $d['deslocamento'] = $r['deslocamento'];
                $d['atendimento'] = $r['atendimento'];
                $d['tempoTotal'] = $r['tempoTotal'];
            }
            $dados[] = $d;
        }

        return $dados;
    }

    /**
     * Retorna todos os usuarios e cargos (lotação) por unidade.
     *
     * @return array
     */
    private function lotacoes($unidadeId = 0, $nomeServico = '')
    {
        $nomeServico = trim($nomeServico);
        if (!empty($nomeServico)) {
            $nomeServico = Strings::sqlLikeParam($nomeServico);
        }

        $unidades = $this->unidadesArray($unidadeId);
        $dados = [];

        $usuarioService = new UsuarioService($this->em());
        $unidadeService = new UnidadeService($this->em());

        foreach ($unidades as $unidade) {
            $lotacoes = $unidadeService->lotacoesComServico($unidade->getId(), $nomeServico);
            $servicos = [];
            foreach ($lotacoes as $lotacao) {
                $servicos[$lotacao->getUsuario()->getId()] = $usuarioService->servicos($lotacao->getUsuario(), $unidade);
            }
            $dados[$unidade->getId()] = [
                'unidade'  => $unidade->getNome(),
                'lotacoes' => $lotacoes,
                'servicos' => $servicos,
            ];
        }

        return $dados;
    }

    /**
     * Retorna todos os cargos e suas permissões.
     *
     * @return array
     */
    private function cargos()
    {
        $dados = [];
        $query = $this->em()->createQuery("SELECT e FROM Novosga\Entity\Cargo e ORDER BY e.nome");
        $cargos = $query->getResult();
        foreach ($cargos as $cargo) {
            $dados[$cargo->getId()] = [
                'cargo'      => $cargo->getNome(),
                'permissoes' => $cargo->getPermissoes(),
            ];
        }

        return $dados;
    }
}
