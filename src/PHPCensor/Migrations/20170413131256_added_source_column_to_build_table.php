<?php

use Phinx\Migration\AbstractMigration;

class AddedSourceColumnToBuildTable extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('build');

        if (!$table->hasColumn('source')) {
            $table->addColumn('source', 'integer', ['limit' => 4, 'default' => 0])->save();
        }
    }

    public function down()
    {
        $table = $this->table('build');

        if ($table->hasColumn('source')) {
            $table->removeColumn('source')->save();
        }
    }
}
