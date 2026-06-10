# enrol_relationship

Plugin de inscrição do Moodle que matricula usuários em cursos — e cria os agrupamentos e grupos correspondentes — a partir dos *relationships* definidos pelo plugin `local_relationship`.

Adaptado de `enrol_cohort`. Cada instância liga um curso a um *relationship*; a partir daí, a sincronização cuida de matrículas, papéis, grupos e membros automaticamente.

## Requisitos

- Moodle com versão mínima `2013110500` (declarado em `version.php`).
- Plugin `local_relationship` instalado (versão mínima `2014051200`).
- Acesso ao usuário do servidor web (`www-data` ou equivalente) para rodar o sync via CLI.

## Instalação

1. Copie este diretório para `enrol/relationship/` dentro da raiz do Moodle.
2. Acesse *Administração do site → Notificações* para que o Moodle execute o registro do plugin.
3. Habilite o plugin em *Administração do site → Plugins → Inscrições → Gerenciar plugins de inscrição*.

Via Composer (a partir de `composer.json`):

```bash
composer require moodle-ufsc/enrol_relationship
```

## Configuração

### Capacidades

| Capacidade | Onde se aplica | Para quê |
| --- | --- | --- |
| `enrol/relationship:config` | Curso | Criar, editar e excluir instâncias do plugin no curso. |
| `enrol/relationship:unenrol` | Curso | Remover manualmente um usuário matriculado por este plugin. |
| `local/relationship:view` | Contexto do *relationship* | Necessária para que o *relationship* apareça no formulário de criação. |
| `moodle/course:managegroups` | Curso | Sem ela, só o modo "somente usuários" fica disponível no formulário. |

### Adicionando uma instância em um curso

1. No curso, vá em *Participantes → Métodos de inscrição* (ou *Usuários → Métodos de inscrição*).
2. Em "Adicionar método", selecione **Relationship**.
3. Preencha:
   - **Nome personalizado da instância** (opcional).
   - **Habilitado**: `Sim`/`Não`.
   - **Relationship**: escolha entre os *relationships* cujo contexto é pai do curso e nos quais você tem `local/relationship:view`.
   - **Tipo de sincronização** (veja abaixo).
   - **Ação ao remover do relationship**: `Cancelar inscrição` ou `Manter inscrição`.

### Tipos de sincronização (`customint2`)

| Constante | Valor | Comportamento |
| --- | --- | --- |
| `RELATIONSHIP_SYNC_USERS_AND_GROUPS` | 0 | Padrão. Sincroniza matrículas e grupos. |
| `RELATIONSHIP_ONLY_SYNC_GROUPS` | 1 | Apenas membros de grupo. Os usuários precisam já estar matriculados por outro método; o plugin os adiciona ao grupo correto quando entram. |
| `RELATIONSHIP_ONLY_SYNC_USERS` | 2 | Apenas matrículas e papéis. Nenhum agrupamento ou grupo é criado/mantido. |

### Ação ao remover do *relationship* (`customint3`)

- `ENROL_EXT_REMOVED_UNENROL`: cancela a inscrição (ou apenas remove o papel, se o usuário tiver outros papéis atribuídos por este plugin).
- `ENROL_EXT_REMOVED_KEEP`: preserva a inscrição mesmo após a remoção do *relationship*.

### Convenção de nomes para grupos e agrupamentos

O plugin identifica seus próprios objetos pelo campo `idnumber`:

- Agrupamento: `relationship_{relationshipid}`
- Grupo: `relationship_{relationshipid}_{relationshipgroupid}`

Não edite esses `idnumber` manualmente — o sync depende deles para reconciliar o estado.

## Uso

### Sincronização automática

A sincronização roda nos seguintes momentos:

- **Eventos do `local_relationship`**: criação/remoção/atualização de *relationships*, grupos e membros disparam atualizações incrementais (ver `db/events.php`).
- **Cron do Moodle**: a cada hora (`$plugin->cron = 3600` em `version.php`), executa o sync completo como rede de segurança.
- **Salvamento de instância**: ao criar ou editar uma instância no formulário, o sync é executado para o curso.

### Sincronização manual via CLI

Útil para depuração ou aplicação imediata após mudanças em massa:

```bash
sudo -u www-data /usr/bin/php enrol/relationship/cli/sync.php
```

Opções:

- `-v`, `--verbose`: imprime cada operação realizada.
- `-h`, `--help`: ajuda.

O comando deve ser executado a partir da raiz do Moodle e com o usuário do servidor web.

## Desinstalação

Remova o plugin pela interface administrativa (*Plugins → Visão geral*). O hook `xmldb_enrol_relationship_uninstall` (em `db/uninstall.php`) cuida de excluir todas as instâncias, grupos criados pelo plugin e atribuições de papel marcadas com `component = 'enrol_relationship'`.

## Licença

GPL v3 ou posterior — ver cabeçalhos dos arquivos.
