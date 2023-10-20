<?php

namespace App\Http\Livewire\Project;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class CloneProject extends Component
{
    public string $project_uuid;
    public string $environment_name;
    public int $project_id;

    public Project $project;
    public $environments;
    public $servers;
    public ?Environment $environment = null;
    public ?int $selectedServer = null;
    public ?Server $server = null;
    public $resources = [];
    public string $newProjectName = '';

    protected $messages = [
        'selectedServer' => 'Please select a server.',
        'newProjectName' => 'Please enter a name for the new project.',
    ];
    public function mount($project_uuid)
    {
        $this->project_uuid = $project_uuid;
        $this->project = Project::where('uuid', $project_uuid)->firstOrFail();
        $this->environment = $this->project->environments->where('name', $this->environment_name)->first();
        $this->project_id = $this->project->id;
        $this->servers = currentTeam()->servers;
        $this->newProjectName = $this->project->name . ' (clone)';
    }

    public function render()
    {
        return view('livewire.project.clone-project');
    }

    public function selectServer($server_id)
    {
        $this->selectedServer = $server_id;
        $this->server = $this->servers->where('id', $server_id)->first();
    }

    public function clone()
    {
        try {
            $this->validate([
                'selectedServer' => 'required',
                'newProjectName' => 'required',
            ]);
            $newProject = Project::create([
                'name' => $this->newProjectName,
                'team_id' => currentTeam()->id,
                'description' => $this->project->description . ' (clone)',
            ]);
            if ($this->environment->id !== 1) {
                $newProject->environments()->create([
                    'name' => $this->environment->name,
                ]);
                $newProject->environments()->find(1)->delete();
            }
            $newEnvironment = $newProject->environments->first();
            // Clone Applications
            $applications = $this->environment->applications;
            $databases = $this->environment->databases();
            $services = $this->environment->services;
            foreach ($applications as $application) {
                $uuid = (string)new Cuid2(7);
                $newApplication = $application->replicate()->fill([
                    'uuid' => $uuid,
                    'fqdn' => generateFqdn($this->server, $uuid),
                    'status' => 'exited',
                    'environment_id' => $newEnvironment->id,
                    'destination_id' => $this->selectedServer,
                ]);
                $newApplication->environment_id = $newProject->environments->first()->id;
                $newApplication->save();
                $environmentVaribles = $application->environment_variables()->get();
                foreach ($environmentVaribles as $environmentVarible) {
                    $newEnvironmentVariable = $environmentVarible->replicate()->fill([
                        'application_id' => $newApplication->id,
                    ]);
                    $newEnvironmentVariable->save();
                }
                $persistentVolumes = $application->persistentStorages()->get();
                foreach ($persistentVolumes as $volume) {
                    $newPersistentVolume = $volume->replicate()->fill([
                        'name' => $newApplication->uuid . '-' . str($volume->name)->afterLast('-'),
                        'resource_id' => $newApplication->id,
                    ]);
                    $newPersistentVolume->save();
                }
            }
            foreach ($databases as $database) {
                $uuid = (string)new Cuid2(7);
                $newDatabase = $database->replicate()->fill([
                    'uuid' => $uuid,
                    'environment_id' => $newEnvironment->id,
                    'destination_id' => $this->selectedServer,
                ]);
                $newDatabase->environment_id = $newProject->environments->first()->id;
                $newDatabase->save();
                $environmentVaribles = $database->environment_variables()->get();
                foreach ($environmentVaribles as $environmentVarible) {
                    $payload = [];
                    if ($database->type() === 'standalone-postgres') {
                        $payload['standalone_postgresql_id'] = $newDatabase->id;
                    } else if ($database->type() === 'standalone_redis') {
                        $payload['standalone_redis_id'] = $newDatabase->id;
                    } else if ($database->type() === 'standalone_mongodb') {
                        $payload['standalone_mongodb_id'] = $newDatabase->id;
                    }
                    $newEnvironmentVariable =  $environmentVarible->replicate()->fill($payload);
                    $newEnvironmentVariable->save();
                }
            }
            foreach ($services as $service) {
                $uuid = (string)new Cuid2(7);
                $newService = $service->replicate()->fill([
                    'uuid' => $uuid,
                    'environment_id' => $newEnvironment->id,
                    'destination_id' => $this->selectedServer,
                ]);
                $newService->environment_id = $newProject->environments->first()->id;
                $newService->save();
                $newService->parse();
            }
            return redirect()->route('project.resources', [
                'project_uuid' => $newProject->uuid,
                'environment_name' => $newEnvironment->name,
            ]);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}