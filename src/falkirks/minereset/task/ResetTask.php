<?php
namespace falkirks\minereset\task;

use falkirks\minereset\MineReset;
use falkirks\minereset\util\BlockStringParser;
use pocketmine\block\BlockFactory;
use pocketmine\math\Vector3;
use pocketmine\scheduler\AsyncTask;
use pocketmine\Server;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\World;

class ResetTask extends AsyncTask {

    /** @var  string */
    private string $name;
    /** @var string $chunks */
    private string $chunks;
    /** @var Vector3 $a */
    private Vector3 $a;
    /** @var Vector3 $b */
    private Vector3 $b;
    /** @var string $ratioData */
    private string $ratioData;
    /** @var int $levelId */
    private int $levelId;
    /** @var Chunk $chunkClass */
    private Chunk $chunkClass;
    /** @var string  */
    private string $parserClass;

    public function __construct(string $name, array $chunks, Vector3 $a, Vector3 $b, array $data, $levelId, $chunkClass){
        $this->name = $name;
        $this->chunks = serialize($chunks);
        $this->a = $a;
        $this->b = $b;
        $this->ratioData = serialize($data);
        $this->levelId = $levelId;
        $this->chunkClass = $chunkClass;
        $this->parserClass = BlockStringParser::class;
    }

    /**
     * Actions to execute when run
     *
     * @return void
     */
    public function onRun() : void {
        $chunkClass = $this->chunkClass;
        /** @var Chunk[] $chunks */
        $chunks = unserialize($this->chunks);
        foreach($chunks as $hash => $binary){
            $chunks[$hash] = FastChunkSerializer::deserialize($binary);
        }
        $sum = [];
        $id = array_map([$this->parserClass, "parse"], array_keys(unserialize($this->ratioData)));

        $m = array_values(unserialize($this->ratioData));
        $sum[0] = $m[0];
        for ($l = 1, $mCount = count($m); $l < $mCount; $l++)
            $sum[$l] = $sum[$l - 1] + $m[$l];

        $sumCount = count($sum);

        //Get these as local variables so they don't keep getting serialized/unserialized every single access
        $posA = $this->a;
        $posB = $this->b;

        $totalBlocks = ($posB->x - $posA->x + 1)*($posB->y - $posA->y + 1)*($posB->z - $posA->z + 1);
        $interval = $totalBlocks / 8; //TODO determine the interval programmatically
        $lastUpdate = 0;
        $currentBlocks = 0;

        $currentChunkX = $posA->x >> 4;
        $currentChunkZ = $posA->z >> 4;

        $currentChunkY = $posA->y >> 4;

        $currentChunk = null;
        $currentSubChunk = null;

        for ($x = $posA->getX(), $x2 = $posB->getX(); $x <= $x2; $x++) {
            $chunkX = $x >> 4;
            for ($z = $posA->getZ(), $z2 = $posB->getZ(); $z <= $z2; $z++) {
                $chunkZ = $z >> 4;
                if($currentChunk === null or $chunkX !== $currentChunkX or $chunkZ !== $currentChunkZ){
                    $currentChunkX = $chunkX;
                    $currentChunkZ = $chunkZ;
                    $currentSubChunk = null;

                    $hash = World::chunkHash($chunkX, $chunkZ);
                    $currentChunk = $chunks[$hash];
                    if($currentChunk === null){
                        continue;
                    }
                }

                for ($y = $posA->getY(), $y2 = $posB->getY(); $y <= $y2; $y++) {
                    $chunkY = $y >> 4;

                    if($currentSubChunk === null or $chunkY !== $currentChunkY){
                        $currentChunkY = $chunkY;

                        $currentSubChunk = $currentChunk->getSubChunk($chunkY);
                        if($currentSubChunk === null){
                            continue;
                        }
                    }

                    $a = rand(0, end($sum));
                    for ($l = 0; $l < $sumCount; $l++) {
                        if ($a <= $sum[$l]) {
                            $currentSubChunk->setFullBlock($x & 0x0f, $y & 0x0f, $z & 0x0f, BlockFactory::getInstance()->get($id[$l][0] & 0xff, $id[$l][1] & 0xff)->getFullId());
                            $currentBlocks++;
                            if($lastUpdate + $interval <= $currentBlocks){
                                if(method_exists($this, 'publishProgress')) {
                                    $this->publishProgress(round(($currentBlocks / $totalBlocks) * 100) . "%");
                                }
                                $lastUpdate = $currentBlocks;
                            }

                            break;
                        }
                    }
                }
            }
        }
        $this->setResult($chunks);
    }

    /**
     * @param Server|null $server
     */
    public function onCompletion(Server $server = null) : void {
        if($server === null){
            $server = Server::getInstance();
        }
        $chunks = $this->getResult();
        $plugin = $server->getPluginManager()->getPlugin("MineReset");
        if($plugin instanceof MineReset and $plugin->isEnabled()) {
            $level = $server->getWorldManager()->getWorld($this->levelId);
            if ($level instanceof World) {
                foreach ($chunks as $hash => $chunk) {
                    World::getXZ($hash, $x, $z);
                    $level->setChunk($x, $z, $chunk, !MineReset::supportsChunkSetting());

                }
            }
            $plugin->getRegionBlockerListener()->clearMine($this->name);
            $plugin->getResetProgressManager()->notifyComplete($this->name);
        }
    }

    /**
     * @param mixed $server
     * @param mixed|null $progress
     */
    public function onProgressUpdate($server, mixed $progress = null): void {
        if($progress === null){
            $progress = $server;
            $server = Server::getInstance();
        }
        $plugin = $server->getPluginManager()->getPlugin("MineReset");
        if($plugin instanceof MineReset and $plugin->isEnabled()) {
            $plugin->getResetProgressManager()->notifyProgress($progress, $this->name);
        }
    }
}