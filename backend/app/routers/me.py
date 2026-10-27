from fastapi import APIRouter, Depends, Query
from app.models.schemas import TransactionListResponse, UsageListResponse, UserResponse, WalletResponse
from app.routers.deps import get_current_user_from_session
from app.services.router_service import router_service
from app.services.wallet_service import wallet_service

router = APIRouter(prefix="/api/v1", tags=["User Profile & Wallet"])


@router.get("/me", response_model=UserResponse)
async def get_me(current_user: UserResponse = Depends(get_current_user_from_session)):
    return current_user


@router.get("/wallet", response_model=WalletResponse)
async def get_wallet(current_user: UserResponse = Depends(get_current_user_from_session)):
    return await wallet_service.get_wallet(current_user.id)


@router.get("/transactions", response_model=TransactionListResponse)
async def get_transactions(
    limit: int = Query(default=20, ge=1, le=100),
    offset: int = Query(default=0, ge=0),
    current_user: UserResponse = Depends(get_current_user_from_session),
):
    return await wallet_service.list_transactions(current_user.id, limit=limit, offset=offset)


@router.get("/usage", response_model=UsageListResponse)
async def get_usage(
    limit: int = Query(default=20, ge=1, le=100),
    offset: int = Query(default=0, ge=0),
    current_user: UserResponse = Depends(get_current_user_from_session),
):
    return await router_service.list_user_usage(current_user.id, limit=limit, offset=offset)
