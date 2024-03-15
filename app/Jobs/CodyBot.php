<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class CodyBot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $game;
    public $strategy = 'ryo';
    public $y = 0;
    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    public function initGame() {
        $ckey = env('CKEY_0');
        $this->game = Http::post("https://game.codyfight.com/?ckey={$ckey}&mode=0");
    }

    public function checkGame() {
        $ckey = env('CKEY_0');
        $this->game = Http::get("https://game.codyfight.com/?ckey={$ckey}");
    }

    public function cast($skillId, $x, $y) {
        $ckey = env('CKEY_0');
        $this->game = Http::patch("https://game.codyfight.com/?ckey={$ckey}&skill_id={$skillId}&x={$x}&y={$y}");
    }

    public function move($x, $y) {
        $ckey = env('CKEY_0');
        $this->game = Http::put("https://game.codyfight.com/?ckey={$ckey}&x={$x}&y={$y}");
    }

    public function waitForOpponent() {
        while ($this->game['state']['status'] == 0) {
            sleep(1);
            $this->checkGame();
        }
    }

    public function playGame() {
        while ($this->game['state']['status'] == 1) {
            if ($this->game['players']['bearer']['is_player_turn']) {
                $this->castSkills();
                $this->makeMove();
            } else {
              sleep(1);
              $this->checkGame();
            }
          }
    }

    public function getRandomTarget($targets) {
        $randomIndex = rand(0, count($targets) - 1);

        return $targets[$randomIndex];
    }

    public function getRandomMove(): array {
        $possibleMoves = array_filter($this->game['players']['bearer']['possible_moves'], function ($move) { return isset($move['type']) ? $move['type'] != 12 : true ;});
      

        return $possibleMoves[rand(0, count($possibleMoves) - 1)];     
    }

    public function makeMove() {
        if ($this->game['players']['bearer']['is_player_turn']) {
            $move = $this->getRandomMove();
            
            $ryo = $this->findSpecialAgent(1);
            $ripper = $this->findSpecialAgent(4);
            $buzz = $this->findSpecialAgent(5);

            $exit = $this->getClosestExit();

            $opponentClass = isset($this->game['players']['opponent']['codyfighter']['class']) ? $this->game['players']['opponent']['codyfighter']['class'] : null;

            $isHunter = $opponentClass == "HUNTER";

            $isHunterNearby = $this->isNearby(
                $this->game['players']['bearer']['position'],
                $this->game['players']['opponent']['position'],
                2
            );

            $isRipperNearby = $this->isNearby(
                $this->game['players']['bearer']['position'],
                $ripper['position'] ?? null,
                3
            );

            if ($ripper && $isRipperNearby) {
                $this->strategy = 'ripper';
                $move = $this->getFarthestDistanceMove(
                    $ripper['position']
                );

                $this->move($move['x'], $move['y']);

                error_log("CBot_0 - Avoiding Ripper");
                return;
            }

            if ($ryo && $buzz) {
                $this->strategy = 'ryo';
                $move = $this->getShortestDistanceMove(
                    array($ryo['position'])
                );

                $this->move($move['x'], $move['y']);
                error_log("CBot_0 - Seeking Ryo");
                return;
            }

            if (!$isHunter) {
                $this->strategy = 'hunter';
                $move = $this->getShortestDistanceMove(
                    array($this->game['players']['opponent']['position'])
                );

                $this->move($move['x'], $move['y']);
                error_log("CBot_0 - Chasing opponent");
                return;
            }

            if ($exit) {
                $this->strategy = 'exit';
                $move = $this->getShortestDistanceMove(array($exit));
                $this->move($move['x'], $move['y']);
                error_log("CBot_0 - Finding Exit");
                return;
            }

            $this->strategy = "stay";

            $move = $this->getShortestDistanceMove(
                array($this->game['players']['bearer']['position']),
            );

            $this->move($move['x'], $move['y']);
            return;
        }
    }

    public function getShortestDistanceMove($targets) {
        $distances = array();
        
        $pits = $this->findPits();
        
        $possibleMoves = array_filter($this->game['players']['bearer']['possible_moves'], function ($position) use ($pits) {
            if ($position['direction'] == 'stay') {
                return true;
            }

            foreach ($pits as $pit) {
                if ($position['x'] == $pit['x'] && $position['y'] == $pit['y']) {
                    return false;
                }
            }

            return true;
        });
        
        foreach ($targets as $position) {
            foreach ($possibleMoves as $possibleMove) {
                $distance = $this->distance($possibleMove['x'], $possibleMove['y'], $position['x'], $position['y']);
                $distances[] = ['move' => $possibleMove, 'distance' => $distance];
            }
        }

        usort($distances, function ($a, $b) {
            return $a['distance'] - $b['distance'];
        });
        

        if ($this->isStaying($distances[0]['move'])) {
          return $this->getRandomMove();
        }
    
        return $distances[0]['move'];
    }

    public function isStaying($move) {
        if (isset($this->game['players']['bearer']['possible_moves'][0])) {
            return (
                $move['x'] === $this->game['players']['bearer']['possible_moves'][0]['x'] &&
                $move['y'] === $this->game['players']['bearer']['possible_moves'][0]['y']
              );
        }

        return false;
        
    }

    public function getFarthestDistanceMove($position) {
        $longestDistance = 0;
        $move = null;
    
        $pits = $this->findPits();
    
        $possibleMoves = array_filter($this->game['players']['bearer']['possible_moves'], function ($position) use ($pits) {
            if ($position['direction'] == 'stay') {
                return true;
            }

            foreach ($pits as $pit) {
                if ($position['x'] == $pit['x'] && $position['y'] == $pit['y']) {
                    return false;
                }
            }

            return true;
        });

        foreach ($possibleMoves as $possibleMove) {
            $distance = $this->distance($possibleMove['x'], $possibleMove['y'], $position['x'], $position['y']);
            if ($distance > $longestDistance) {
                $longestDistance = $distance;
                $move = $possibleMove;
            }
        }

        return $move;
      }

    public function isNearby($position, $specialAgentPosition, $distance = 1) {
        return (
          $this->distance(
            $position['x'] ?? null,
            $position['y'] ?? null,
            $specialAgentPosition['x'] ?? null,
            $specialAgentPosition['y'] ?? null,
          ) <= $distance
        );
    }
    public function getTargetPosition($possibleTargets, $target, $towards = true) {
        $distances = array();
    
        foreach ($possibleTargets as $position) {
            $distance = $this->distance($position['x'], $position['y'], $target['x'], $target['y']);
            $distances[] = ['position' => $position, 'distance' => $distance];

            if ($towards) {
                usort($distances, function ($a, $b) {
                    return $a['distance'] - $b['distance'];
                });
            } else {
                usort($distances, function ($a, $b) {
                    return $b['distance'] - $a['distance'];
                });
            }
        }

        return $distances[0]['position'] ?? null;
    }

    public function castSkills() {
        if ($this->game['players']['bearer']['is_player_turn'] === false) {
            return;
        }
        
        foreach ($this->game['players']['bearer']['skills'] as $skill) {
            $hasEnoughEnergy = $skill['cost'] <= $this->game['players']['bearer']['stats']['energy'];

            if ($skill['status'] !== 1 || count($skill['possible_targets']) ==0 || !$hasEnoughEnergy) 
                continue;

            $exitPos = $this->getClosestExit();
            $ryoPos = isset($this->findSpecialAgent(1)['position']) ? $this->findSpecialAgent(1)['position'] : null;
            $ripperPos = isset($this->findSpecialAgent(4)['position']) ? $this->findSpecialAgent(4)['position'] : null;
            $opponentPos = $this->game['players']['opponent']['position'];
            $pitHoles = $this->findPits();
            $possibleTargets = array_filter($skill['possible_targets'], function ($target) use ($skill, $opponentPos, $pitHoles) {
                if ($skill['id'] == 1) {
                    foreach ($pitHoles as $hole) {
                        if ($hole['x'] == $target['x'] && $hole['y'] == $target['y']) {
                            return true;
                        }
                    }
                }

                if ($skill['damage']) {
                    return $target['x'] == $opponentPos['x'] && $target['y'] == $opponentPos['y'];
                }

                foreach ($pitHoles as $hole) {
                    if ($hole['x'] == $target['x'] && $hole['y'] == $target['y']) {
                        return false;
                    }
                }
            });

            if (count($possibleTargets) == 0) continue;

            $bestTarget = null;
            switch ($this->strategy) {
                case "exit":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $exitPos);
                    break;
                case "ryo":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $ryoPos);
                    break;
                
                case "ripper":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $ripperPos, false);    
                    break;

                case "hunter":
                    $bestTarget = $this->getTargetPosition($possibleTargets, $opponentPos, true);    
                    break;
                case "stay":
                    $bestTarget = null;   
                    break;
              
            }

            $target = $bestTarget;

            if ($target == null) continue;

            foreach ($skill['possible_targets'] as $possibleTarget) {
                if ($possibleTarget['x'] == $target['x'] && $possibleTarget['y'] == $target['y']) {
                    $this->cast($skill['id'], $target['x'], $target['y']);
                    $skillName = $skill['name'];
                    $skillId = $skill['id'];
                    error_log(
                        "CBot_0 - ⚡️ Casting {$skillName} - id: {$skillId}"
                      );
                    break;
                }
            }

            $this->castSkills();
            break;
        }
    }

    public function findPits() {
        $map = $this->game['map'];

        $this->y = 0;
        return array_reduce($map, function ($pits, $row) {
            foreach ($row as $x => $tile) {
                if ($tile['type'] == 12) {
                    $pits[] = ['x' => $x, 'y' => $this->y];
                }
            }
            $this->y = $this->y + 1;
            return $pits;
        }, array());    
    }
    public function findExits() {
        $map = $this->game['map'];
        $this->y = 0;
        return array_reduce($map, function ($exits, $row) {
            foreach ($row as $x => $tile) {
                if ($tile['type'] == 2) {
                    $exits[] = ['x' => $x, 'y' => $this->y];
                }
            }
            $this->y = $this->y + 1;
            return $exits;
        }, array());    
    }

    public function distance ($x1, $y1, $x2, $y2) {
        $a = $x1 - $x2;
        $b = $y1 - $y2;
    
        return sqrt ($a * $a + $b * $b);
    }

    public function getClosestExit()
    {
        $exits = $this->findExits();

        $distances = array();

        foreach ($exits as $exit) {
            $distance = $this->distance($this->game['players']['bearer']['position']['x'], $this->game['players']['bearer']['position']['y'], $exit['x'], $exit['y']);

            $distances[] = ['exit' => $exit, 'distance' => $distance];
        }
        
        usort($distances, function ($a, $b) {
            return $a['distance'] - $b['distance'];
        });

        return $distances[0]['exit'] ?? null;
    }

    public function findSpecialAgent ($type) {
        foreach ($this->game['special_agents'] as $agent) {
            if ($agent['type'] == $type) {
                return $agent;
            }
        }

        return null;
    }

    public function play(): void
    {
        $this->initGame();
        $this->waitForOpponent();
        $this->playGame();

        $this->endGame();
    }

    public function endGame() {
        if ($this->game['state']['status'] == 2) {
            $player = $this->game['players']['bearer']['name'];
            $opponent = $this->game['players']['opponent']['name'];
            $verdict = 'draw';
            switch ($this->game['verdict']['winner'])
            {
                case $opponent:
                    $verdict = $opponent;
                    break;
                case $player: 
                    $verdict = $player;
                    break;
            };

            error_log("CBot_0 {$player} game ended! winner: {$verdict}");
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        while (true) {
            try {
                $this->play();
                sleep(60);
            } catch (Exception $e) {
                error_log($e);
            }
        }
    }
}
