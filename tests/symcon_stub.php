<?php
declare(strict_types=1);
$inputs = [1001 => 71.0, 1002 => 27000.5, 1003 => true, 1004 => 11300.0, 1005 => 500000.0, 1006 => 7200.0];
function IPS_SemaphoreEnter(string $k,int $t): bool {return true;}
function IPS_SemaphoreLeave(string $k): void {}
function IPS_VariableExists(int $id): bool {global $inputs;return array_key_exists($id,$inputs);}
function GetValue(int $id): mixed {global $inputs,$objectStore;return $inputs[$id] ?? ($objectStore[$id] ?? null);}
class IPSModule
{
    public int $InstanceID = 25;
    protected array $props=[],$attrs=[],$vars=[];
    protected int $counter=2000;
    public function __construct() { $this->Create(); }
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString(string $key,string $value):void{$this->props[$key]=$value;}
    public function RegisterPropertyInteger(string $key,int $value):void{$this->props[$key]=$value;}
    public function RegisterPropertyBoolean(string $key,bool $value):void{$this->props[$key]=$value;}
    public function RegisterPropertyFloat(string $key,float $value):void{$this->props[$key]=$value;}
    public function ReadPropertyString(string $key):string{return (string) $this->props[$key];}
    public function ReadPropertyInteger(string $key):int{return (int) $this->props[$key];}
    public function ReadPropertyBoolean(string $key):bool{return (bool) $this->props[$key];}
    public function ReadPropertyFloat(string $key):float{return (float) $this->props[$key];}
    public function RegisterAttributeString(string $key,string $value):void{$this->attrs[$key]=$value;}
    public function ReadAttributeString(string $key):string{return $this->attrs[$key]??'';}
    public function WriteAttributeString(string $key,string $value):void{$this->attrs[$key]=$value;}
    public function RegisterTimer(string $name,int $interval,string $command):void{}
    public function SetTimerInterval(string $name,int $interval):void{}
    public function SetStatus(int $status):void{}
    public function RegisterVariableFloat(string $id,string $caption,string $profile,int $pos):int{return $this->reg($id);}
    public function RegisterVariableInteger(string $id,string $caption,string $profile,int $pos):int{return $this->reg($id);}
    public function RegisterVariableBoolean(string $id,string $caption,string $profile,int $pos):int{return $this->reg($id);}
    public function RegisterVariableString(string $id,string $caption,string $profile,int $pos):int{return $this->reg($id);}
    private function reg(string $id):int {return $this->vars[$id]??($this->vars[$id]=++$this->counter);}
    public function SetValue(string $key,mixed $value):void {global $objectStore;$objectStore[$this->vars[$key]]=$value;}
    public function GetIDForIdent(string $key):int{return $this->vars[$key];}
    public function SendDebug(string $a,string $b,int $c):void {}
    public function ReloadForm():bool{return true;}
    public function config(string $key,mixed $value):void{$this->props[$key]=$value;}
    public function getTestValue(string $key):mixed{return GetValue($this->vars[$key]);}
}
require_once __DIR__ . '/../RenaultEVCenter/module.php';
$module=new RenaultEVCenter();
$module->config('UseRenaultCloud',false);
$module->config('SourceSOC',1001);
$module->config('SourceMileage',1002);
$module->config('GoEChargingVar',1003);
$module->config('GoEPowerVar',1004);
$module->config('GoETotalKwhVar',1005);
$module->config('SolarPVVar',1006);
$module->ApplyChanges();
$form=json_decode($module->GetConfigurationForm(),true);
if (!is_array($form) || !isset($form['elements'],$form['actions'])) throw new RuntimeException('Invalid configuration form');
if (!$module->Update()) throw new RuntimeException('Update failed: '.$module->getTestValue('Message'));
if ($module->getTestValue('BatteryLevel')!==71.0) throw new RuntimeException('Local SOC lost');
if ($module->getTestValue('PVPower')!==7.2) throw new RuntimeException('PV unit conversion error');
if (!$module->getTestValue('ChargeSessionActive')) throw new RuntimeException('Charging status missing');
if ($module->getTestValue('CloudConnected')) throw new RuntimeException('Cloud should be offline');
if (!str_contains($module->getTestValue('Dashboard'), 'Renault EV Center')) throw new RuntimeException('Dashboard missing');
echo "PASS: simulated IP-Symcon module create/configure/read/update/dashboard\n";
