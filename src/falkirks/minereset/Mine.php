<?php
namespace falkirks\minereset;

use falkirks\minereset\exception\InvalidBlockStringException;
use falkirks\minereset\exception\InvalidStateException;
use falkirks\minereset\exception\JsonFieldMissingException;
use falkirks\minereset\exception\MineResetException;
use falkirks\minereset\exception\WorldNotFoundException;
use falkirks\minereset\task\ResetTask;
use falkirks\minereset\util\BlockStringParser;
use pocketmine\math\Vector3;
use pocketmine\scheduler\Task;
use pocketmine\world\format\Chunk;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * Class Mine
 *
 * Programmer note: Mine objects have no state. They can be generated arbitrarily from serialized data.
 *
 * @package falkirks\minereset\mine
 */
class Mine extends Task implements \JsonSerializable {

    private Vector3 $pointA;
    private Vector3 $pointB;
    private string $level;
    private array $data;
    private string $name;
    private bool $isResetting;

    private int $resetInterval;
    private string $warpName;

    private MineManager $api;

    /**
     * Mine constructor.
     * @param MineManager $api
     * @param Vector3 $pointA
     * @param Vector3 $pointB
     * @param string $levelName
     * @param string $name
     * @param array $data
     * @param int $resetInterval
     * @param string $warpName
     */
    public function __construct(MineManager $api,
                                Vector3 $pointA,
                                Vector3 $pointB,
                                string $levelName,
                                string $name,
                                array $data = [],
                                int $resetInterval = -1,
                                string $warpName = ""){
        $this->pointA = $pointA;
        $this->pointB = $pointB;
        $this->level = $levelName;
        $this->data = $data;
        $this->name = $name;
        $this->resetInterval = $resetInterval;
        $this->warpName = $warpName;
        $this->api = $api;

        $this->isResetting = false;


        if($this->isValid()) {
            $this->register();
        }
        else{
            $api->getApi()->getLogger()->warning("MineReset has detected corruption of the mines.yml file in mine with name {$this->name}, MineReset will not reset this mine.");
        }

    }

    public function isValid() : bool {
        foreach ($this->data as $id => $percent){
            if(!BlockStringParser::isValid($id) || !is_numeric($percent)){
                return false;
            }
        }
        return true;
    }


    /**
     * INTERNAL USE ONLY
     */
    public function register(){
        if($this->getHandler() === null && $this->resetInterval > 0){
            $this->getApi()->getApi()->getScheduler()->scheduleRepeatingTask($this, 20 * $this->resetInterval);
        }
    }

    /**
     * INTERNAL USE ONLY
     */
    public function destroy(){
        if($this->getHandler() !== null) {
           // $this->getApi()->getApi()->getScheduler()->cancelTask($this->getTaskId());
        }
    }

    public function onRun(): void {
        try {
            $this->reset();
        }
        catch(MineResetException $e){
            $this->getApi()->getApi()->getLogger()->debug("Background reset timer raised an exception --> " . $e->getMessage());
        }
    }

    /**
     * @return Vector3
     */
    public function getPointA(): Vector3{
        return $this->pointA;
    }

    /**
     * @return Vector3
     */
    public function getPointB(): Vector3{
        return $this->pointB;
    }

    public function isPointInside(Position $position): bool{
        if($this->getWorld() !== null && $position->getWorld()->getId() !== $this->getWorld()->getId()){
            return false;
        }

        return $position->getX() >= $this->getPointA()->getX()
            && $position->getX() <= $this->getPointB()->getX()
            && $position->getY() >= $this->getPointA()->getY()
            && $position->getY() <= $this->getPointB()->getY()
            && $position->getZ() >= $this->getPointA()->getZ()
            && $position->getZ() <= $this->getPointB()->getZ();
    }

    /**
     * @return World | null
     */
    public function getWorld(): ?World {
        return $this->api->getApi()->getServer()->getWorldManager()->getWorldByName($this->level);
    }

    /**
     * @return string
     */
    public function getLevelName(): string {
        return $this->level;
    }

    /**
     * @return array
     */
    public function getData(): array{
        return $this->data;
    }

    /**
     * @param array $data
     */
    public function setData(array $data){
        $this->data = $data;
        $this->getApi()->offsetSet($this->getName(), $this);
    }

    /**
     * @return string
     */
    public function getName(): string{
        return $this->name;
    }

    /**
     * @return MineManager
     */
    public function getApi(): MineManager{
        return $this->api;
    }

    /**
     * @return bool
     */
    public function isResetting(): bool
    {
        return $this->isResetting;
    }

    public function hasWarp(): bool
    {
        return $this->warpName !== "";
    }

    public function getWarpName(): string
    {
        return $this->warpName;
    }

    /**
     * @param bool $force NOT TESTED
     * @throws InvalidBlockStringException
     * @throws InvalidStateException
     * @throws WorldNotFoundException
     */
    public function reset(bool $force = false){
        if($this->isResetting() && !$force){
            throw new InvalidStateException();
        }
        if($this->getWorld() === null){
            throw new WorldNotFoundException();
        }
        if(!$this->isValid()){
            throw new InvalidBlockStringException();
        }
        $this->isResetting = true;

        $chunks = [];
        $chunkClass = Chunk::class;
        for ($x = $this->getPointA()->getX(); $x-16 <= $this->getPointB()->getX(); $x += 16){
            for ($z = $this->getPointA()->getZ(); $z-16 <= $this->getPointB()->getZ(); $z += 16) {
                $chunk = $this->getWorld()->getChunk($x >> 4, $z >> 4);

                $chunkClass = get_class($chunk);
                $chunks[World::chunkHash($x >> 4, $z >> 4)] = FastChunkSerializer::serialize($chunk);
            }
        }

        $resetTask = new ResetTask($this->getName(), $chunks, $this->getPointA(), $this->getPointB(), $this->data, $this->getWorld()->getId(), $chunkClass);
        $this->getApi()->getApi()->getServer()->getAsyncPool()->submitTask($resetTask);
    }

    /**
     * @return int
     */
    public function getResetInterval(): int{
        return $this->resetInterval;
    }

    /**
     * @param int $resetInterval
     */
    public function setResetInterval(int $resetInterval){
        $this->resetInterval = $resetInterval;
        $this->destroy();
        $this->register();
    }

    public function doneReset(){
        $this->isResetting = false;
    }

    public function __toString(){
        return $this->name;
    }

    public function jsonSerialize(): array {
        return [
            'name' => $this->name,
            'pointA' => [$this->pointA->getX(), $this->pointA->getY(), $this->pointA->getZ()],
            'pointB' => [$this->pointB->getX(), $this->pointB->getY(), $this->pointB->getZ()],
            'level' => $this->level,
            'data' => $this->data,
            'resetInterval' => $this->resetInterval,
            'warpName' => $this->warpName
        ];
    }

    /**
     * @param MineManager $manager
     * @param $json
     * @param $name
     * @return Mine
     * @throws JsonFieldMissingException
     */
    public static function fromJson(MineManager $manager, $json, $name): Mine{
        if(isset($json['pointA']) && isset($json['pointB']) && isset($json['level']) && isset($json['data'])){
            return new Mine($manager,
                new Vector3(...$json['pointA']),
                new Vector3(...$json['pointB']),
                $json['level'],
                $name,
                $json['data'],
                $json['resetInterval'] ?? -1,
                $json['warpName'] ?? "");
        }
        throw new JsonFieldMissingException();
    }
}
