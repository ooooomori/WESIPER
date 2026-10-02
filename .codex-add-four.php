<?php
require __DIR__ . '/backend/lib/player-school.php';
$c=require '/opt/bitnami/apache/conf/wesiper-db.php';
$p=new PDO("mysql:host={$c['host']};dbname={$c['database']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$rows=[
 ['12','타카다','두산','투수','좌타','좌투','2002-09-18','179cm, 84kg','일본 시즈오카상업고','26 두산 아시아쿼터','구원',null,'국외','국외',56212,'2026_doo_p_l'],
 ['74','사토시','삼성','투수','우타','우투','1998-05-28','193cm, 93kg','일본 유통경제대학','26 삼성 아시아쿼터','구원',null,'국외','국외',56474,'2026_sam_p_r'],
 ['39','이이무라','롯데','투수','우타','우투','1998-04-28','183cm, 85kg','일본 카스미가우라고-일본 오비린대','26 롯데 아시아쿼터','구원',null,'국외','국외',56503,'2026_lot_p_r'],
 ['63','시라카와','KIA','투수','우타','우투','2001-06-04','180cm, 88kg','일본 이케타고-SSG-두산','24 SSG 부상 대체 외국인선수','선발',null,'국외','국외',54843,'2026_kia_p_r'],
];
$p->beginTransaction();
try{
 $q=$p->prepare('SELECT COUNT(*) FROM kbo_player_data WHERE player_id=?');
 foreach($rows as $r){$q->execute([$r[14]]);if($q->fetchColumn())throw new RuntimeException("already exists {$r[1]}");}
 $q=$p->prepare('INSERT INTO kbo_player_data (backNo,name,oldname,team,pos,bat,`throw`,birth,body,school,draft,is_kbodle,mainPos,subPos,hs,hsLoc,player_id,img) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
 foreach($rows as $r){$q->execute([$r[0],$r[1],null,$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],playerSchoolOnly($r[8]),$r[9],2,$r[10],$r[11],$r[12],$r[13],$r[14],$r[15]]);}
 $p->commit(); echo json_encode($rows,JSON_UNESCAPED_UNICODE),PHP_EOL;
}catch(Throwable $e){if($p->inTransaction())$p->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
