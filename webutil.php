<?php

function chunk_start() {
	global $chunking;
	if ($chunking) {
		throw new Exception("already chunking");
	}

	$chunking = true;
	if (ob_get_level() == 0) {
		ob_start();
	}

	header("Content-type: text/html; charset=utf-8");
	header("X-Content-Type-Options: nosniff");
	header("Transfer-encoding: chunked");
}

function chunk($str) {
	global $chunking;
	if (!$chunking) {
		throw new Exception("not chunking");
	}

	printf("%s\r\n", dechex(strlen($str)));
	printf("%s\r\n", $str);
	ob_flush();
	flush();
	if ($str == '') {
		$chunking = false;
		ob_end_flush();
	}
}

function chunk_end() {
	chunk("");
}

class DTimer {
	private $last;
	public function __construct(){
		$this->last=0;
		$this->tick();
	}
	public function tick(){
		$cur=hrtime(true);
		$dt=$cur-$this->last;
		$this->last=$cur;
		return $dt/1000_000_000;
	}
}

class StyleMutator {
	private $current;
	private $dirty;
	private $write;
	public function __construct(Callable $write,?StyleMutator $from=null){
		if(isset($from)) {
			$this->current=&$from->current;
			$this->dirty=&$from->dirty;
		}else{
			$this->current=[];
			$this->dirty=[];
		}
		$this->write=$write;
	}
	public function set($i,$k,$v){
		$wasset=isset($this->dirty[$k]);
		$this->dirty[$k][$i]=$v;
		if(isset($this->current[$k][$i])&&$this->current[$k][$i]==$v) {
			if($wasset){
				//print("clear $i $k $v\n");
			}
			unset($this->dirty[$k][$i]);
		} else {
		//	print("set $i $k $v\n");
		//	echo "afsd: ";print_r($this->dirty);
		}

	}
	public function present(){
		$str="<style>%s</style>\n";
		$byval=[];
		//echo 'diety: ';print_r($this->dirty);
		foreach($this->dirty as $k=>$l) {
			foreach($l as $i=>$v) {
				$byval[$k.":".$v][]=$i;
			}
		}
		//echo 'byval: ';print_r($byval);
		foreach($byval as $v=>$l){
			$byval[$v]=sprintf("%s{%s}",implode(',',$l),$v);
		}
		//echo 'byval2: ';print_r($byval);
		($this->write)(sprintf($str,implode('',$byval)));
		foreach($this->dirty as $k=>$l) {
			foreach($l as $i=>$v) {
				$this->current[$k][$i]=$v;
			}
		}
		$this->dirty=[];
		return true;
	}
}

const SI_BIGGER=[
	['k',1000**1],
	['M',1000**2],
	['G',1000**3],
	['T',1000**4],
	['P',1000**5],
	['E',1000**6],
	['Z',1000**7],
	['Y',1000**8],
	['R',1000**9],
	['Q',1000**10],
];
const SI_SMALLER=[
	['m',1000**-1],
	['μ',1000**-2],
	['n',1000**-3],
	['p',1000**-4],
	['a',1000**-5],
	['z',1000**-6],
	['y',1000**-7],
	['r',1000**-8],
	['q',1000**-9],
];

function fnumfitweak($n,$m) {
	$dd=max($m-strlen(sprintf("%.0f",$n))-1,0);
	$s=rtrim(sprintf("%.{$dd}f",$n),".0");
	return $s==''?'0':$s;
}

function fnumfit($n,$m) {
	$p='';
	$s=fnumfitweak($n,$m);
	foreach(SI_SMALLER as [$vp,$vm]){
		if(!($n>0&&$s==0)){
			break;
		}
		$p=$vp;
		$s=fnumfitweak($n/$vm,$m-1);
	}
	if($p!='') {
		return [$s,$p];
	}
	foreach(SI_BIGGER as [$vp,$vm]){
		if(strlen($s.$p)<=$m){
			break;
		}
		$p=$vp;
		$s=fnumfitweak($n/$vm,$m-1);
	}
	return [$s,$p];
}

class CursedNumber {
	private $stylist;
	private $maxlen;
	private $name;
	private const DIGITS=[0,1,2,3,4,5,6,7,8,9,'.'=>'s'];
	public function __construct(?StyleMutator &$stylist=null,?string $name=null,?Callable $write=null,int $maxlen=5){
		$this->stylist=&$stylist;
		$this->maxlen=$maxlen;
		if(isset($stylist)){
			if(!(isset($name)&&isset($write))){
				throw new Exception("invalid argument");
			}
			$this->name=$name;
			$str="<span id=$name>";
			$state="none";
			for($i=0;$i<$maxlen;$i++){
				foreach(self::DIGITS as $v=>$d) {
					$en=$this->elname('d',$i,$d);
					$str.="<span id=$en>$v</span>";
					$this->stylist->set('#'.$en,"display",$state);
				}
			}
			foreach([...SI_BIGGER,...SI_SMALLER] as [$d,$v]){
				$en=$this->elname('p',$d);
				$str.="<span id=$en>$d</span>";
				$this->stylist->set('#'.$en,"display",$state);
			}
			$str.="</span>";
			$write($str);
		}
	}
	private function elname($type,...$l) {
		$name=$this->name;
		switch($type){
		case "d":
			return "{$name}n{$l[0]}{$l[1]}";
		case "p":
			return "{$name}p{$l[0]}";
		}
	}
	public function draw($n){
		$n=fnumfit($n,$this->maxlen);
		for($i=0;$i<$this->maxlen;$i++) {
			foreach(self::DIGITS as $v=>$d) {
				$en=$this->elname('d',$i,$d);
				$state="none";
				if(isset($n[0][$i])&&$n[0][$i]==$v) {
					$state="initial";
				}
				$this->stylist->set('#'.$en,"display",$state);
			}
		}
		foreach([...SI_BIGGER,...SI_SMALLER] as [$d,$v]){
			$en=$this->elname('p',$d);
			$state="none";
			if($n[1]==$d){
				$state="initial";
			}
			$this->stylist->set('#'.$en,"display",$state);
		}
	}
}
