<?php
/**
 * @author Skorobogatko Alexei <skorobogatko.oleksii@gmail.com>
 * @copyright 2015
 * @version $Id$
 * @since 1.0.0
 */

namespace skoro\stardict;

use PDO;

/**
 * Converts dictionary to database.
 */
class PDOConverter extends Converter
{

    /**
     * @var PDO
     */
    protected $pdo;
    
    /**
     * @var integer dictionary ID in dicts table.
     */
    protected $dict_id;
    
    /**
     * @var array
     */
    protected $params = [
        'initSchema' => false,
        'tableDicts' => 'dicts',
        'tableWords' => 'words',
        'tableDictWords' => 'dict_words',
        'transaction' => true,
    ];
    
    /**
     * @var \PDOStatement prepared statement for selecting word.
     */
    protected $stmtCheckWord;
    
    /**
     * @var \PDOStatement prepared statement for adding new word.
     */
    protected $stmtAddWord;
    
    /**
     * @var \PDOStatement prepared statement for adding links.
     */
    protected $stmtDictWords;
    
    /**
     * Get PDO driver name.
     *
     * @return string
     */
    protected function driverName()
    {
        return $this->getPDO()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
    
    /**
     * Returns pdo exception.
     *
     * @return \RuntimeException
     */
    protected function PDOException()
    {
        $err = $this->pdo->errorInfo();
        return new \RuntimeException(sprintf('SQLSTATE: "%s", Error code: "%s", Message: "%s"', $err[0], $err[1], $err[2]));
    }

    /**
     * @param PDO $pdo
     * @return PDOConverter
     */
    public function setPDO(PDO $pdo)
    {
        $this->pdo = $pdo;
        return $this;
    }
    
    /**
     * @return PDO
     */
    public function getPDO()
    {
        if (empty($this->pdo)) {
            throw new RuntimeException('PDO not initialized.');
        }
        return $this->pdo;
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->getPDO() instanceof PDO) {
            throw new \RuntimeException('PDO not initialized.');
        }
        
        if ($this->params['transaction'] && !$this->pdo->beginTransaction()) {
            throw $this->PDOException();
        }

        if ($this->params['initSchema']) {
            $this->initSchema();
        }
        
        $this->addDictionary();
        $this->prepareStatements();
    }
    
    /**
     * Initialize database schema.
     */
    public function initSchema()
    {
        switch ($this->driverName()) {
            case 'sqlite':
                $pk = 'INTEGER PRIMARY KEY';
                break;
            
            default:
                $pk = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        }
    
        $sql = <<<EOF
DROP TABLE IF EXISTS {$this->params['tableDicts']};
CREATE TABLE {$this->params['tableDicts']} (
    id {$pk},
    dict VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL DEFAULT '',
    website VARCHAR(255) NOT NULL DEFAULT '',
    desc TEXT DEFAULT ''
);
DROP TABLE IF EXISTS {$this->params['tableWords']};
CREATE TABLE {$this->params['tableWords']} (
    id {$pk},
    word VARCHAR(255) NOT NULL UNIQUE
);
DROP TABLE IF EXISTS {$this->params['tableDictWords']};
CREATE TABLE {$this->params['tableDictWords']} (
    dict_id INT UNSIGNED NOT NULL,
    word_id INT UNSIGNED NOT NULL,
    data TEXT
);
DROP INDEX IF EXISTS idx_dict_words;
CREATE UNIQUE INDEX idx_dict_words ON {$this->params['tableDictWords']}(dict_id, word_id);
EOF;
        if ($this->getPDO()->exec($sql) === false) {
            throw $this->PDOException();
        }
    }
    
    /**
     * Add dictionary to dicts table.
     *
     * @throws \RuntimeException
     */
    public function addDictionary()
    {
        $sql = "INSERT INTO {$this->params['tableDicts']} (dict, author, website, desc) VALUES (:dict, :author, :website, :desc)";
        
        if (($sth = $this->getPDO()->prepare($sql)) === false) {
            throw $this->PDOException();
        }
        
        $sth->execute([
            ':dict' => $this->info->bookname,
            ':author' => $this->info->author,
            ':website' => $this->info->website,
            ':desc' => $this->info->description,
        ]);
        
        if ($sth === false) {
            throw $this->PDOException();
        }
        
        $this->dict_id = (int) $this->pdo->lastInsertId();
    }
    
    public function prepareStatements()
    {
        $statements = [
            'stmtCheckWord' => "SELECT id FROM {$this->params['tableWords']} WHERE word = :word",
            'stmtAddWord' => "INSERT INTO {$this->params['tableWords']} (word) VALUES (:word)",
            'stmtDictWords' => "INSERT INTO {$this->params['tableDictWords']} (dict_id, word_id, data) VALUES (:dict_id, :word_id, :data)",
        ];
        
        foreach ($statements as $var => $sql) {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                throw $this->PDOException();
            }
            $this->$var = $stmt;
        }
    }
    
    /**
     * @inheritdoc
     */
    public function process($word, $data)
    {
        $this->stmtCheckWord->execute([':word' => $word]);
        $exists = $this->stmtCheckWord->fetch();
        if ($exists === false) {
            $this->stmtAddWord->execute([':word' => $word]);
            $word_id = (int) $this->pdo->lastInsertId();
        } else {
            $word_id = $exists['id'];
        }
        
        $this->stmtDictWords->execute([
            ':dict_id' => $this->dict_id,
            ':word_id' => $word_id,
            ':data' => $data,
        ]);
    }
    
    /**
     * @inheritdoc
     */
    public function done()
    {
        if ($this->params['transaction'] && !$this->getPDO()->commit()) {
            $this->PDOException();
        }
    }
    
    /**
     * @inheritdoc
     * Rollback transaction on any exception.
     */
    protected function catchException(\Exception $e)
    {
        if ($this->getPDO()->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
    
}

