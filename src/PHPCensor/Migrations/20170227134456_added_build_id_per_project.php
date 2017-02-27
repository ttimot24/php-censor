<?php

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\PdoAdapter;
use b8\Store\Factory;
use PHPCensor\Store\ProjectStore;
use PHPCensor\Store\BuildStore;
use PHPCensor\Model\Project;
use PHPCensor\Model\Build;

class AddedBuildIdPerProject extends AbstractMigration
{
    public function up()
    {
        $table = $this->table('build');
        if (!$table->hasColumn('id_per_project')) {
            $table
                ->addColumn('id_per_project', PdoAdapter::PHINX_TYPE_INTEGER, ['null' => true])
                ->save();
        }
        
        /** @var ProjectStore $projectStore */
        $projectStore     = Factory::getStore('Project');
        $projectsActive   = $projectStore->getAll(false);
        $projectsArchived = $projectStore->getAll(true);
        
        /** @var Project[] $projects */
        $projects = array_merge($projectsActive['items'], $projectsArchived['items']);
        
        /** @var BuildStore $buildStore */
        $buildStore = Factory::getStore('Build');
        
        foreach ($projects as $project) {
            $sql   = 'SELECT * FROM {{build}} WHERE {{project_id}} = :project_id ORDER BY {{id}} ASC';
            $query = \b8\Database::getConnection()->prepareCommon($sql);
            $query->bindValue(':project_id', $project->getId());

            if ($query->execute()) {
                $builds = $query->fetchAll(\PDO::FETCH_ASSOC);
                $i      = 1;
                foreach ($builds as $build) {
                    $buildObject = new Build($build);
                    $buildObject->setIdPerProject($i);
                    $buildStore->save($buildObject);
                    $i++;
                }
            }
        }
    }

    public function down()
    {
        $table = $this->table('build');
        if ($table->hasColumn('id_per_project')) {
            $table
                ->removeColumn('id_per_project')
                ->save();
        }
    }
}
