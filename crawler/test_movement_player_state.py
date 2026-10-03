import unittest
from datetime import date
from movement_player_state import plan_state_updates,apply_state_updates

TODAY=date(2026,10,3)
START=date(2026,9,19)


def movement(kind,team,day='2026-10-02',**extra):
    return dict(player_id=12345,source_key=kind+'|'+team+'|'+str(day),event_date=day,event_type=kind,team=team,note='',**extra)


class MovementStateTest(unittest.TestCase):
    def plan(self,rows,team='삼성',status=1):
        return plan_state_updates(rows,{12345:{'team':team,'is_kbodle':status}},TODAY,START)

    def test_domestic_transfer_keeps_curated_status(self):
        updates,pending=self.plan([movement('트레이드','LG')])
        self.assertEqual(updates[0]['changes'],{'team':'LG'})
        self.assertFalse(pending)

    def test_domestic_transfer_preserves_excluded_status(self):
        updates,_=self.plan([movement('트레이드','LG')],status=2)
        self.assertEqual(updates[0]['changes'],{'team':'LG'})

    def test_domestic_return_uses_active_excluded(self):
        for status in [0,3,4]:
            updates,_=self.plan([movement('소속선수 추가 등록','LG')],status=status)
            self.assertEqual(updates[0]['changes'],{'team':'LG','is_kbodle':2})

    def test_free_agent_disables_without_changing_team(self):
        updates,_=self.plan([movement('자유계약선수','삼성')])
        self.assertEqual(updates[0]['changes'],{'is_kbodle':0})

    def test_overseas_is_three_without_changing_team(self):
        for team in ['美샌프란시스코','日오릭스','샌디에이고','LA 다저스']:
            updates,_=self.plan([movement('FA 계약',team)])
            self.assertEqual(updates[0]['changes'],{'is_kbodle':3})

    def test_ulsan_is_four(self):
        for team in ['울산','울산웨일즈','울산 웨일즈']:
            updates,_=self.plan([movement('이적',team)])
            self.assertEqual(updates[0]['changes'],{'team':'울산','is_kbodle':4})

    def test_same_day_rejoining_beats_release(self):
        updates,_=self.plan([movement('트레이드','LG'),movement('자유계약선수','삼성')])
        self.assertEqual(updates[0]['changes'],{'team':'LG'})

    def test_later_registration_beats_release(self):
        updates,_=self.plan([movement('자유계약선수','삼성','2026-10-01'),movement('소속선수 추가 등록','LG')])
        self.assertEqual(updates[0]['changes'],{'team':'LG'})

    def test_old_undated_and_future_records_ignored(self):
        rows=[movement('FA 계약','美볼티모어','2026-09-01'),movement('FA 계약','美볼티모어',None),movement('FA 계약','美볼티모어','2026-10-04')]
        self.assertEqual(self.plan(rows),([],[]))

    def test_injury_number_and_military_events_do_not_change_state(self):
        rows=[movement(k,'삼성') for k in ['부상자 명단','등번호 변경','군보류','FA 자격취득','군보류 자유계약선수']]
        self.assertEqual(self.plan(rows),([],[]))

    def test_ambiguous_destination_needs_review(self):
        updates,pending=self.plan([movement('트레이드','LG'),movement('트레이드','NC')])
        self.assertFalse(updates);self.assertEqual(len(pending),1)

    def test_unknown_destination_is_not_overseas(self):
        updates,pending=self.plan([movement('소속선수 추가 등록','상무')])
        self.assertFalse(updates);self.assertEqual(len(pending),1)

    def test_idempotent(self):
        self.assertEqual(self.plan([movement('트레이드','LG')],team='LG'),([],[]))

    def test_dry_run_never_writes(self):
        updates,_=self.plan([movement('트레이드','LG')])
        parents={12345:{'team':'삼성','is_kbodle':1}}
        apply_state_updates(None,parents,updates,False)
        self.assertEqual(parents[12345]['team'],'LG')

    def test_concurrent_state_change_raises(self):
        class Cursor:
            rowcount=0
            def __enter__(self):return self
            def __exit__(self,*args):pass
            def execute(self,*args):pass
        class Connection:
            def cursor(self):return Cursor()
        updates,_=self.plan([movement('트레이드','LG')])
        with self.assertRaisesRegex(ValueError,'Concurrent'):
            apply_state_updates(Connection(),{12345:{'team':'삼성','is_kbodle':1}},updates,True)


if __name__=='__main__':unittest.main()
