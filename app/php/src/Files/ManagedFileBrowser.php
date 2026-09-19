<?php
declare(strict_types=1);

namespace DigiOps\Files;

use DigiOps\Registry\ProjectRegistry;
use DigiOps\Security\PathGuard;
use RuntimeException;

final class ManagedFileBrowser
{
    public function __construct(private ProjectRegistry $projects = new ProjectRegistry()) {}

    public function list(string $projectId, string $scope = 'public', string $relative = ''): array
    {
        $project = $this->projects->find($projectId);
        if (!$project) throw new RuntimeException('PROJECT_NOT_FOUND');
        $rootRel = $scope === 'private' ? $project['privatePath'] : $project['publicPath'];
        $root = DIGIOPS_APP_HOME . '/' . rtrim($rootRel,'/');
        $path = $this->safePath($root, $relative);
        if (!is_dir($path)) return ['path'=>$relative,'items'=>[]];
        $items=[];
        foreach (array_diff(scandir($path) ?: [], ['.','..']) as $name) {
            if (str_starts_with($name,'.')) continue;
            $full=$path.'/'.$name;
            $items[]=['name'=>$name,'type'=>is_dir($full)?'dir':'file','size'=>is_file($full)?filesize($full):null,'modified'=>date(DATE_ATOM,filemtime($full)?:time())];
        }
        usort($items,fn($a,$b)=>($a['type']===$b['type']?strnatcasecmp($a['name'],$b['name']):($a['type']==='dir'?-1:1)));
        return ['path'=>$relative,'items'=>$items];
    }

    private function safePath(string $root, string $relative): string
    {
        $relative=str_replace('\\','/',trim($relative));
        if ($relative==='' || $relative==='.') return $root;
        if (str_starts_with($relative,'/') || str_contains($relative,'..') || !preg_match('#^[A-Za-z0-9._/-]+$#',$relative)) throw new RuntimeException('INVALID_PATH');
        $candidate=$root.'/'.trim($relative,'/');
        $parent=realpath(dirname($candidate));
        $rootReal=realpath($root) ?: $root;
        if ($parent!==false && !str_starts_with($parent,$rootReal)) throw new RuntimeException('PATH_ESCAPE');
        return $candidate;
    }
}
